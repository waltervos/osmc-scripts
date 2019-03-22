<?php
/**
 * Description of RtorrentManager
 *
 * @author waltervos
 */

class RtorrentManager {
    private $rtorrent_client;
    private $sonarr_client;

    private $queued_torrents = array();
    private $active_torrents = false;
    private $leeching_torrents = false;
    private $loaded_torrents = false;
    private $completed_torrents = false;
    private $torrent_files = array();
    
    public function __construct() {
        $this->rtorrent_client = new RtorrentClient(Config::getValue('unix_socket'), Config::getValue('scgi_timeout'));
        $this->sonarr_client = new SonarrClient(Config::getValue('sonarr_api_key'));

        $this->closeCompletedTorrents();
        $this->runQueueManager();
        $this->throttleActiveTorrents();
        $this->setDestinationOnSonarrTorrents();
    }

    private function setDestinationOnSonarrTorrents() {
        foreach ($this->getLeechingTorrents() as $leeching_torrent) {
            if (substr($leeching_torrent['base_path'], -4) === 'meta') {
                Log::logTrace("Torrent %1s hasn't downloaded metadata yet. Not checking of setting the destination for torrent", $leeching_torrent['base_path']);
                continue;
            }
            if ($leeching_torrent['d.custom1'] == Config::getValue('sonarr_category')) {
                if ($path = $this->sonarr_client->getDestinationForTorrent($leeching_torrent['hash'])) {
                    if ($leeching_torrent['d.custom2'] != $path) {
                        Log::logInfo("Setting destination for %1s as %2s", $leeching_torrent['hash'], $path);
                        $this->rtorrent_client->setTorrentAttribute($leeching_torrent['hash'], 'custom2', $path);
                    }
                } else {
                    Log::logWarn("SonarrClient::getDestinationForTorrent returned false %1s", $leeching_torrent['hash']);
                }
            }
        }
    }

    private function closeCompletedTorrents() {
        // Loop through all completed torrents
        foreach ($this->getCompletedTorrents() as $completed_torrent) {
            // Torrents from private trackers will only be closed manually
            if ($completed_torrent['throttle_name'] == 'private_up') {
                continue;
            }

            $log_suffix = '.';

            // Close completed torrents from public trackers:
            $this->rtorrent_client->closeTorrent($completed_torrent['hash']);
            if (Config::getValue('erase_completed')) {
                $this->rtorrent_client->deleteTorrent($completed_torrent['hash']);
                $log_suffix = ' and erased.';
            }

            Log::logInfo('Completed torrent %1s was stopped%2s', $completed_torrent['hash'], $log_suffix);
        }
    }

    private function runQueueManager() {
        Log::logTrace('Running queue manager.');
        
        if ($this->canQueue() && $this->hasQueue()) {
            // How many new torrents can we load?
            Log::logTrace('%d new torrents can be queued', $this->getQueueBudget());

            // Load the queued torrents, until queueBudget is spent
            $i = 0;
            foreach ($this->getQueuedTorrents() as $queued_torrent) {
                if ($this->getQueueBudget() === $i) {
                    Log::logTrace('Queue budget spent, breaking');
                    break;
                 } else {
                    $throttle = ($queued_torrent['private']) ? 'private_up' : 'public_up';
                    $custom2 = Config::getValue('completed_root') . $queued_torrent['custom2'];
                    $view = $this->getViewForTorrent($queued_torrent);
                    if (!is_dir($custom2)) {
                        $dir = mkdir($custom2, 0755);
                        if (!$dir) {
                            Log::logWarn('Creation of directory %s failed', $custom2);
                            break;
                        } else Log::logInfo('Directory %s created',  $custom2);
                    }
                    if ($this->rtorrent_client->loadTorrent(
                            $queued_torrent['tied_to_file'],
                            array(
                                'd.custom2.set=' . $custom2,
                                'd.custom3.set=' . $queued_torrent['custom3'],
                                'd.throttle_name.set=' . $throttle,
                                'view.set_visible=' . $view
                            ),
                            Config::getValue('load_method')
                        )) { // ... if($this->rtorrent_client->loadTorrent()):
                        Log::logInfo("Torrent %s loaded.", $queued_torrent['tied_to_file']);
                        $i++;
                    } else {
                        Log::logInfo("Torrent %s could not be loaded.", $queued_torrent['tied_to_file']);
                    }
                }
            }
        }
    }

    private function throttleActiveTorrents() {
        foreach ($this->getActiveTorrents() as $active_torrent) {
            if (substr($active_torrent['base_path'], -4) === 'meta') {
                // Torrents that have a [hash].meta base_path are magnets that haven't downloaded metadata yet. We'll leave those be.
                Log::logTrace('%s hasn\'t downloaded metadata yet. Not setting throttle on this torrent.', $active_torrent['base_path']);
            } elseif (empty($active_torrent['throttle_name'])) {
                $throttle = $active_torrent['d.is_private'] == 1 ? 'private_up' : 'public_up';
                Log::logInfo("%1s doesn't have throttle applied yet. Setting throttle %2s.", $active_torrent['base_path'], $throttle);
                $this->rtorrent_client->pauseTorrent($active_torrent['hash']);
                $this->rtorrent_client->setTorrentAttribute($active_torrent['hash'], 'throttle_name', $throttle);
                $this->rtorrent_client->resumeTorrent($active_torrent['hash']);
            } else {
                continue;
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
        Log::logTrace('%d torrents are currently active', count($this->getActiveTorrents()));
        Log::logTrace('Allowed number of active torrents is %d', var_export(Config::getValue('max_active'), true));

        // Check to see if there's a limit on active torrents or if there's room for more
        if (!Config::getValue('max_active') || (count($this->getActiveTorrents()) < Config::getValue('max_active'))) {
            Log::logTrace('%d torrents are currently leeching', count($this->getLeechingTorrents()));
            Log::logTrace('Allowed number of leeching torrents is %d', var_export(Config::getValue('max_leeching'), true));

            // Check to see if there's a limit on leeching torrents or if there's room for more
            if (!Config::getValue('max_leeching') || (count($this->getLeechingTorrents()) < Config::getValue('max_leeching'))) {
                return true;
            }
        }
        Log::logTrace('No room to queue additional torrents.');
        return false;
    }

    private function hasQueue() {
        $this->setTorrentFiles();
        Log::logTrace('%d torrent files in watch dir', count($this->torrent_files));

        // Get and set the queued torrents
        $this->setQueuedTorrents();
        if (empty($this->getQueuedTorrents())) {
            Log::logTrace('Torrent queue empty');
            return false;
        }
        Log::logTrace('%d torrents in queue', count($this->getQueuedTorrents()));
        return true;
    }

    // Determines how many new torrents can be added, no setter for this one.
    private function getQueueBudget() {
        return Config::getValue('max_leeching') - count($this->leeching_torrents);
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
        $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(Config::getValue('torrent_root')));
        while ($dir->valid()) {
            if ($dir->isFile()) {
                $torrent_data = PHP\BitTorrent\Torrent::createFromTorrentFile($dir->key());
                $custom2 = $dir->getSubPath();
                $custom3 = isset($this->copy_paths[$dir->getSubPath()]) ? Config::getValue('completed_root') . $this->copy_paths[$dir->getSubPath()] : 0;
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
            $this->getTorrentFiles(), $this->getLoadedTorrents(), array($this, "diffTorrentArrays")
        );
    }

    private function diffTorrentArrays(array $reference, array $subject) {
        // If info hash is not available for comparison, use torrent filename
        if ($reference['hash'] === '') {
            Log::logTrace('Hash not available for %s', $reference['tied_to_file']);
            if ($reference['tied_to_file'] === $subject['tied_to_file']) {
                Log::logTrace('Torrent matches loaded torrent %s based on filename', $reference['tied_to_file']);
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
