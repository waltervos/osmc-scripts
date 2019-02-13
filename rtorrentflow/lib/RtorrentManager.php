<?php
/**
 * Description of RtorrentManager
 *
 * @author waltervos
 */

class RtorrentManager {
    private $rtorrent_client;
    private $sonarr_client;
    
    // How many torrents to download simultaneously? false = unlimited (not recommended)
    private $max_leeching = 1;
 
    // How many torrents may be active (including leeching)? false = unlimited
    private $max_active = 12;

    // What is the root directory for torrent files?
    private $torrent_root = '/home/osmc/Torrents';

    // What is the root directory to move completed downloads to?
    private $completed_root = '/home/osmc/';

    private $copy_paths = array(
        'downloads/whatcd' => 'muziek/'
    );

    // What is the location of the rtorrent unix socket?
    private $unix_socket = '/home/osmc/.run/rtorrent.socket';
    
    private $load_method = 'load_start';

    private $queued_torrents = array();
    private $active_torrents = false;
    private $leeching_torrents = false;
    private $loaded_torrents = false;
    private $completed_torrents = false;
    private $torrent_files = array();
    
    public function __construct() {
    }
    
    public function setMaxLeeching($max_leeching) {
        $this->max_leeching = $max_leeching;
    }
    
    public function setMaxActive($max_active) {
        $this->max_active = $max_active;
    }
    
    public function setTorrentRoot($torrent_root) {
        $this->torrent_root = $torrent_root;
    }
    
    public function setCompletedRoot($completed_root) {
        $this->completed_root = $completed_root;
    }

    public function setUnixSocket($unix_socket) {
        $this->unix_socket = $unix_socket;
    }
    
    public function setLoadMethod($load_method) {
        $this->load_method = $load_method;
    }

    private function setClients() {
        if (is_null($this->rtorrent_client)) $this->rtorrent_client = new RtorrentClient($this->unix_socket);
        if (is_null($this->sonarr_client)) $this->sonarr_client = new SonarrClient();
    }
    
    public function setDestinationOnSonarrTorrents($api_key) {
        $this->setClients();
        $this->sonarr_client->setApiKey($api_key);

        foreach ($this->getLeechingTorrents() as $leeching_torrent) {
            if (substr($leeching_torrent['base_path'], -4) === 'meta') {
                // Torrents that have a [hash].meta base_path are magnets that haven't downloaded metadata yet. We'll leave those be.
                Log::addMessage($leeching_torrent['base_path'] . ' hasn\'t downloaded metadata yet. Not checking or setting the destination for torrent.', 'debug');
                continue;
            }
            if ($leeching_torrent['d.custom1'] == 'tv-sonarr') {
                if ($path = $this->sonarr_client->getDestinationForTorrent($leeching_torrent['hash'])) {
                    if ($leeching_torrent['d.custom2'] != $path) {
                        Log::addMessage('Setting destination for ' . $leeching_torrent['tied_to_file'] . ' as ' . $path, 'debug');
                        $this->rtorrent_client->setTorrentAttribute($leeching_torrent['hash'], 'custom2', $path);
                    }
                } else {
                    Log::addMessage('SonarrClient::getDestinationForTorrent returned false for ' . $leeching_torrent['tied_to_file'], 'debug');
                }
            }
        }
    }

    public function closeCompletedTorrents($erase = false) {
        $this->setClients();
        
        // Loop through all completed torrents
        foreach ($this->getCompletedTorrents() as $completed_torrent) {
            // Torrents from private trackers will only be closed manually
            if ($completed_torrent['throttle_name'] == 'private_up') {
                continue;
            }

            $log_suffix = null;

            // Close completed torrents from public trackers:
            $this->rtorrent_client->closeTorrent($completed_torrent['hash']);
            if ($erase) {
                $this->rtorrent_client->deleteTorrent($completed_torrent['hash']);
                $log_suffix = ' and erased';
            }

            Log::addMessage('Completed torrent ' . $completed_torrent['tied_to_file'] . ' was stopped' . $log_suffix . '.', 'info');
        }
    }

    public function runQueueManager() {
        $this->setClients();
        Log::addMessage('Running queue manager', 'debug');
        
        if ($this->canQueue() && $this->hasQueue()) {
            // How many new torrents can we load?
            Log::addMessage($this->getQueueBudget() . ' new torrents can be queued', 'debug');

            // Load the queued torrents, until queueBudget is spent
            $i = 0;
            foreach ($this->getQueuedTorrents() as $queued_torrent) {
                if ($this->getQueueBudget() === $i) {
                    Log::addMessage('Queue budget spent, breaking', 'debug');
                    break;
                 } else {
                    $throttle = ($queued_torrent['private']) ? 'private_up' : 'public_up';
                    $custom2 = $this->completed_root . $queued_torrent['custom2'];
                    $view = $this->getViewForTorrent($queued_torrent);
                    if (!is_dir($custom2)) {
                        $dir = mkdir($custom2, 0755);
                        if (!$dir) {
                            Log::addMessage('Creation of directory ' . $custom2 . ' failed', 'debug');
                            break;
                        } else Log::addMessage('Directory ' . $custom2 . ' created', 'debug');
                    }
                    if ($this->rtorrent_client->loadTorrent(
                            $queued_torrent['tied_to_file'],
                            array(
                                'd.custom2.set=' . $custom2,
                                'd.custom3.set=' . $queued_torrent['custom3'],
                                'd.throttle_name.set=' . $throttle,
                                'view.set_visible=' . $view, $this->load_method
                            )
                        )) { // ... if($this->rtorrent_client->loadTorrent()):
                        Log::addMessage("Torrent " . $queued_torrent['tied_to_file'] . " loaded.", 'info');
                        $i++;
                    } else {
                        Log::addMessage("Torrent " . $queued_torrent['tied_to_file'] . " could not be loaded.", 'info');
                    }
                }
            }
        }
    }

    public function throttleActiveTorrents() {
        $this->setClients();
        foreach ($this->getActiveTorrents() as $active_torrent) {
            if (substr($active_torrent['base_path'], -4) === 'meta') {
                // Torrents that have a [hash].meta base_path are magnets that haven't downloaded metadata yet. We'll leave those be.
                Log::addMessage($active_torrent['base_path'] . ' hasn\'t downloaded metadata yet. Not setting throttle on this torrent.', 'debug');
                continue;
            }
            if (empty($active_torrent['throttle_name'])) {
                $throttle = ($active_torrent['d.is_private'] === 1) ? 'private_up' : 'public_up';
                $this->rtorrent_client->pauseTorrent($active_torrent['hash']);
                $this->rtorrent_client->setTorrentAttribute($active_torrent['hash'], 'throttle_name', $throttle);
                $this->rtorrent_client->resumeTorrent($active_torrent['hash']);
            }
        }
    }

    private function getViewForTorrent($torrent) {
        $view = 'regular_view';
        foreach ($torrent['announce'] as $announce) {
            if (strpos($announce, 'torrentday') || strpos($announce, 'iptorrents') || strpos($announce, 'td.jumbohostpro') || strpos($announce, 'empornium')) {
                $view = 'setratio_view';
            }
        }
        return $view;
    }

    private function canQueue() {
        // Get and set the list of active torrents
        $this->setActiveTorrents();
        Log::addMessage(count($this->getActiveTorrents()) . ' active torrents', 'debug');

        // Check to see if there's a limit on active torrents or if there's room for more
        if (!$this->max_active || (count($this->getActiveTorrents()) < $this->max_active)) {
            // Get and set the list of leeching torrents
            Log::addMessage(count($this->getLeechingTorrents()) . ' leeching torrents', 'debug');
            Log::addMessage(count($this->getLoadedTorrents()) . ' loaded torrents', 'debug');

            // Check to see if there's a limit on leeching torrents or if there's room for more
            if (!$this->max_leeching || (count($this->leeching_torrents) < $this->max_leeching)) {
                return true;
            }
        }
        return false;
    }

    private function hasQueue() {
        $this->setTorrentFiles();
        Log::addMessage(count($this->torrent_files) . ' torrent files in watch dir', 'debug');

        // Get and set the queued torrents
        $this->setQueuedTorrents();
        if (empty($this->getQueuedTorrents())) {
            Log::addMessage('Torrent queue empty', 'debug');
            return false;
        }
        Log::addMessage(count($this->getQueuedTorrents()) . ' torrents in queue', 'debug');
        return true;
    }

    // Determines how many new torrents can be added
    private function getQueueBudget() {
        return $this->max_leeching - count($this->leeching_torrents);
    }
    
    private function getLeechingTorrents() {
        if (!$this->leeching_torrents) {
            $this->setLeechingTorrents();
        }
        return $this->leeching_torrents;
    }

    private function setLeechingTorrents() {
        $this->leeching_torrents = $this->rtorrent_client->getTorrents('leeching');
    }

    private function getLoadedTorrents() {
        if (!$this->loaded_torrents) {
            $this->setLoadedTorrents();
        }
        return $this->loaded_torrents;
    }

    private function setLoadedTorrents() {
        $this->loaded_torrents = $this->rtorrent_client->getTorrents('main');
    }

    private function getCompletedTorrents() {
        if (!$this->completed_torrents) {
            $this->setCompletedTorrents();
        }
        return $this->completed_torrents;
    }

    private function setCompletedTorrents() {
        // Completed torrents are found in the 'complete' view and not in the 'hashing' view
        $this->completed_torrents = array_udiff(
            $this->rtorrent_client->getTorrents('complete'),
            $this->rtorrent_client->getTorrents('hashing'),
            array($this, "diffTorrentArrays")
        );
    }

    private function getActiveTorrents() {
        if (!$this->active_torrents) {
            $this->setActiveTorrents();
        }
        return $this->active_torrents;
    }

    private function setActiveTorrents() {
        $this->active_torrents = $this->rtorrent_client->getTorrents('active');
    }

    private function getTorrentFiles() {
        if (!$this->torrent_files) {
            $this->setTorrentFiles();
        }
        return $this->torrent_files;
    }

    private function setTorrentFiles() {
        $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->torrent_root));
        while ($dir->valid()) {
            if ($dir->isFile()) {
                $torrent_data = PHP\BitTorrent\Torrent::createFromTorrentFile($dir->key());
                $custom2 = $dir->getSubPath();
                $custom3 = isset($this->copy_paths[$dir->getSubPath()]) ? $this->completed_root . $this->copy_paths[$dir->getSubPath()] : 0;
                Log::addMessage("custom3 for " . $dir->key() . " would be $custom3", 'debug');
                $announce = array(0 => $torrent_data->getAnnounce());
                $announce_list = $torrent_data->getAnnounceList();
                if ($announce[0] == '' && is_array($announce_list)) {
                    unset($announce[0]);
                    foreach ($announce_list as $key => $announce_entry) {
                        $announce[$key] = $announce_entry[0];
                    }
                }
                $this->torrent_files[] = array(
                    'hash' => strtoupper($torrent_data->getHash()),
                    'private' => $torrent_data->isPrivate(),
                    'announce' => $announce,
                    'tied_to_file' => $dir->key(),
                    'custom2' => $custom2,
                    'custom3' => $custom3,
                );
            }
            $dir->next();
        }
    }

    private function getQueuedTorrents() {
        if (!$this->queued_torrents) {
            $this->setQueuedTorrents();
        }
        return $this->queued_torrents;
    }

    private function setQueuedTorrents() {
        $this->queued_torrents = array_udiff(
            $this->torrent_files, $this->loaded_torrents, array($this, "diffTorrentArrays")
        );
    }

    private function diffTorrentArrays(array $reference, array $subject) {
        // If info hash is not available for comparison, use torrent filename
        if ($reference['hash'] === '') {
            Log::addMessage('Hash not available for ' . $reference['tied_to_file'], 'debug');
            if ($reference['tied_to_file'] === $subject['tied_to_file']) {
                Log::addMessage('Torrent matches loaded torrent ' . $reference['tied_to_file'] . ' based on filename', 'debug');
                return 0;
            }
            // Hash is unavailable and torrent filenames don't match. Return:
            return ($reference['tied_to_file'] < $subject['tied_to_file']) ? -1 : 1;
        }
        if ($reference['hash'] === $subject['hash']) {
            return 0;
        }
        return ($reference['hash'] < $subject['hash']) ? -1 : 1;
    }

}
?>
