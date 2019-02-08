<?php
/**
 * Description of RtorrentManager
 *
 * @author waltervos
 */

class RtorrentManager {
    private $rtorrent_client;
    
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
    
    public function __construct() {}
    
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

    public function closeCompletedTorrents($erase = false) {
        if (is_null($this->rtorrent_client)) $this->rtorrent_client = new RtorrentClient($this->unix_socket, $this->load_method);

        // Get and set the list of completed torrents
        $this->setCompletedTorrents();

        // Loop through all completed torrents
        foreach ($this->completed_torrents as $completed_torrent) {
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
        if (is_null($this->rtorrent_client)) $this->rtorrent_client = new RtorrentClient($this->unix_socket, $this->load_method);
        
        Log::addMessage('Running queue manager', 'debug');
        
        if ($this->canQueue() && $this->hasQueue()) {
            // How many new torrents can we load?
            $queue_budget = $this->getQueueBudget();
            Log::addMessage($queue_budget . ' new torrents can be queued', 'debug');
        
            // Load the queued torrents, until queueBudget is spent
            $i = 0;
            foreach ($this->queued_torrents as $queued_torrent) {
                if ($queue_budget === $i) {
                    Log::addMessage('Queue budget spent, breaking', 'debug');
                    break;
                 } else {
                    $throttle = ($queued_torrent['private']) ? 'private_up' : 'public_up';
                    $custom1 = $this->completed_root . $queued_torrent['custom1'];
                    $view = $this->getView($queued_torrent);
                    if (!is_dir($custom1)) {
                        $dir = mkdir($custom1, 0755);
                        if (!$dir) {
                            Log::addMessage('Creation of directory ' . $custom1 . ' failed', 'debug');
                            break;
                        } else Log::addMessage('Directory ' . $custom1 . ' created', 'debug');
                    }
                    if ($this->rtorrent_client->loadTorrent($queued_torrent['tied_to_file'], array('d.set_custom1=' . $custom1, 'd.set_custom2=' . $queued_torrent['custom2'], 'd.set_throttle_name=' . $throttle, 'view.set_visible=' . $view))) {
                        Log::addMessage("Torrent " . $queued_torrent['tied_to_file'] . " loaded.", 'info');
                        $i++;
                    } else {
                        Log::addMessage("Torrent " . $queued_torrent['tied_to_file'] . " could not be loaded.", 'info');
                    }
                }
            }
        }
    }

    private function getView($torrent) {
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
        Log::addMessage(count($this->active_torrents) . ' active torrents', 'debug');

        // Check to see if there's a limit on active torrents or if there's room for more
        if (!$this->max_active || (count($this->active_torrents) < $this->max_active)) {
            // Get and set the list of leeching torrents
            $this->setLeechingTorrents();
            $this->setLoadedTorrents();
            Log::addMessage(count($this->leeching_torrents) . ' leeching torrents', 'debug');
            Log::addMessage(count($this->loaded_torrents) . ' loaded torrents', 'debug');

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
        if (empty($this->queued_torrents)) {
            Log::addMessage('Torrent queue empty', 'debug');
            return false;
        }
        Log::addMessage(count($this->queued_torrents) . ' torrents in queue', 'debug');
        return true;
    }

    private function getQueueBudget() {
        return $this->max_leeching - count($this->leeching_torrents);
    }

    private function setLeechingTorrents() {
        if (!$this->leeching_torrents) {
            $this->leeching_torrents = $this->rtorrent_client->getTorrents('leeching');
        }
    }

    private function setLoadedTorrents() {
        if (!$this->loaded_torrents) {
            $this->loaded_torrents = $this->rtorrent_client->getTorrents('main');
        }
    }
    
    private function setCompletedTorrents() {
        if (!$this->completed_torrents) {
            // Completed torrents are found in the 'complete' view and not in the 'hashing' view
            $this->completed_torrents = array_udiff(
                $this->rtorrent_client->getTorrents('complete'),
                $this->rtorrent_client->getTorrents('hashing'),
                array($this, "diffTorrentArrays")
            );
            foreach ($this->completed_torrents as $key => $completed_torrent) {
                $torrent = PHP\BitTorrent\Torrent::createFromTorrentFile($completed_torrent['tied_to_file']);
                $this->completed_torrents[$key]['private'] = $torrent->isPrivate(); // Why do we need this?
            }
        }
    }

    private function setActiveTorrents() {
        if (!$this->active_torrents) {
            $this->active_torrents = $this->rtorrent_client->getTorrents('active');
        }
    }

    private function setTorrentFiles() {
        if (!$this->torrent_files) {
            $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->torrent_root));
            while ($dir->valid()) {
                if ($dir->isFile()) {
                    $torrent_data = PHP\BitTorrent\Torrent::createFromTorrentFile($dir->key());
                    $custom1 = $dir->getSubPath();
                    $custom2 = isset($this->copy_paths[$dir->getSubPath()]) ? $this->completed_root . $this->copy_paths[$dir->getSubPath()] : 0;
                    Log::addMessage("custom2 for " . $dir->key() . " is $custom2", 'debug');
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
                        'custom1' => $custom1,
                        'custom2' => $custom2,
                    );
                }
                $dir->next();
            }
        }
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
