<?php
abstract class FOGService extends FOGBase {
    protected $dev = '';
    protected $log = '';
    protected $zzz = '';
    protected $ips = array();
    private $transferLog = array();
    public $procRefs = array();
    public $procPipes = array();
    protected function getIPAddress() {
        $output = array();
        exec("/sbin/ip addr | awk -F'[ /]+' '/global/ {print $3}'",$IPs,$retVal);
        if (!count($IPs)) exec("/sbin/ifconfig -a | awk '/(cast)/ {print $2}' | cut -d':' -f2",$IPs,$retVal);
        foreach ($IPs AS $i => &$IP) {
            $IP = trim($IP);
            if (filter_var($IP,FILTER_VALIDATE_IP)) $output[] = $IP;
            $output[] = gethostbyaddr($IP);
        }
        unset($IP);
        $this->ips = array_values(array_unique((array)$output));
        return $this->ips;
    }
    protected function checkIfNodeMaster() {
		$this->getIPAddress();
        $StorageNodes = $this->getClass('StorageNodeManager')->find(array('isMaster'=>1,'isEnabled'=>1));
        foreach ($StorageNodes AS $i => &$StorageNode) {
            if (!$StorageNode->isValid()) continue;
            if (!in_array($this->FOGCore->resolveHostname($StorageNode->get('ip')),$this->ips)) continue;
            return $StorageNode;
		}
        throw new Exception(' | '._('This is not the master node'));
    }
    public function wait_interface_ready() {
        $this->getIPAddress();
        if (!count($this->ips)) {
            $this->out('Interface not ready, waiting.',$this->dev);
            sleep(10);
            $this->wait_interface_ready();
        }
        foreach ($this->ips AS $i => &$ip) $this->out("Interface Ready with IP Address: $ip",$this->dev);
        unset($ip);
    }
    public function wait_db_ready() {
        while ($this->DB->link()->connect_errno) {
            $this->out('FOGService: '.get_class($this).' - Waiting for mysql to be available',$this->dev);
            sleep(10);
        }
    }
    public function getBanner() {
        $str = "\n";
        $str .= "        ___           ___           ___      \n";
        $str .= "       /\  \         /\  \         /\  \     \n";
        $str .= "      /::\  \       /::\  \       /::\  \    \n";
        $str .= "     /:/\:\  \     /:/\:\  \     /:/\:\  \   \n";
        $str .= "    /::\-\:\  \   /:/  \:\  \   /:/  \:\  \  \n";
        $str .= "   /:/\:\ \:\__\ /:/__/ \:\__\ /:/__/_\:\__\ \n";
        $str .= "   \/__\:\ \/__/ \:\  \ /:/  / \:\  /\ \/__/ \n";
        $str .= "        \:\__\    \:\  /:/  /   \:\ \:\__\   \n";
        $str .= "         \/__/     \:\/:/  /     \:\/:/  /   \n";
        $str .= "                    \::/  /       \::/  /    \n";
        $str .= "                     \/__/         \/__/     \n";
        $str .= "\n";
        $str .= "  ###########################################\n";
        $str .= "  #     Free Computer Imaging Solution      #\n";
        $str .= "  #     Credits:                            #\n";
        $str .= "  #     http://fogproject.org/credits       #\n";
        $str .= "  #     GNU GPL Version 3                   #\n";
        $str .= "  ###########################################\n";
        $this->outall($str);
    }
    public function outall($string) {
        $this->out($string."\n",$this->dev);
        $this->wlog($string."\n",$this->log);
        return;
    }
    protected function out($string,$device) {
        $strOut = $string."\n";
        if (!$hdl = fopen($device,'w')) return;
        if (fwrite($hdl,$strOut) === false) return;
        fclose($hdl);
    }
    protected function getDateTime() {
        return $this->nice_date()->format('m-d-y g:i:s a');
    }
    protected function wlog($string, $path) {
        if (file_exists($path) && filesize($path) >= LOGMAXSIZE) unlink($path);
        if (!$hdl = fopen($path,'a')) $this->out("\n * Error: Unable to open file: $path\n",$this->dev);
        if (fwrite($hdl,sprintf('[%s] %s',$this->getDateTime(),$string)) === FALSE) $this->out("\n * Error: Unable to write to file: $path\n",$this->dev);
    }
    public function serviceStart() {
        $this->outall(sprintf(' * Starting %s Service',get_class($this)));
        $this->outall(sprintf(' * Checking for new items every %s seconds',$this->zzz));
        $this->outall(' * Starting service loop');
        return;
    }
    public function serviceRun() {
        $this->out('',$this->dev);
        $this->out('+---------------------------------------------------------',$this->dev);
    }
    /** replicate_items() replicates data without having to keep repeating
     * @param $myStorageGroupID int this servers groupid
     * @param $myStorageNodeID int this servers nodeid
     * @param $Obj object that is trying to send data, e.g. images, snapins
     * @param $master bool set if sending to master->master or master->nodes
     * auto sets to false
     */
    protected function replicate_items($myStorageGroupID,$myStorageNodeID,$Obj,$master = false) {
        unset($username,$password,$ip,$remItem,$myItem,$limitmain,$limitsend,$limit,$includeFile);
        if (count($this->procRef)) {
            $replication = false;
            foreach ((array)$this->procRef AS $i => $procRef) {
                if ($this->isRunning($procRef)) {
                    $replication = true;
                    $this->outall(sprintf(_('Replication not complete running with pid %d'),$this->getPID($procRef)));
                }
                unset($procRef);
            }
            if ($replication === true) throw new Exception(_(' * Waiting for previous replication to complete'));
        }
        $message = $onlyone = false;
        $itemType = $master ? 'group' : 'node';
        $findWhere['isEnabled'] = 1;
        $findWhere['isMaster'] = (int)$master;
        $findWhere['storageGroupID'] = $master ? $Obj->get('storageGroups') : $myStorageGroupID;
        $StorageNode = $this->getClass('StorageNode',$myStorageNodeID);
        if (!($StorageNode->isValid() && $StorageNode->get('isMaster'))) throw new Exception(_('I am not the master'));
        $objType = get_class($Obj);
        $groupOrNodeCount = $this->getClass('StorageNodeManager')->count($findWhere);
        $countTest = ($master ? 1 : 0);
        if ($groupOrNodeCount <= $countTest) {
            $this->outall(_(" * Not syncing $objType between $itemType(s)"));
            $this->outall(_(" | $objType Name: ".$Obj->get('name')));
            $this->outall(_(" | I am the only member"));
            $onlyone = true;
        }
        unset($countTest);
        if (!$onlyone) {
            $this->outall(sprintf(" * Found $objType to transfer to %s %s(s)",$groupOrNodeCount,$itemType));
            $this->outall(sprintf(" | $objType name: %s",$Obj->get('name')));
            $getPathOfItemField = $objType == 'Snapin' ? 'snapinpath' : 'ftppath';
            $getFileOfItemField = $objType == 'Snapin' ? 'file' : 'path';
            $PotentialStorageNodes = array_diff((array)$this->getSubObjectIDs('StorageNode',$findWhere,'id'),(array)$myStorageNodeID);
            $myAddItem = sprintf('/%s%s',trim($StorageNode->get($getPathOfItemField),'/'),($master ? sprintf('/%s',$Obj->get($getFileOfItemField)) : ''));
            if (is_file($myAddItem)) $myAddItem = sprintf('%s/',dirname($myAddItem));
            foreach ((array)$this->getClass('StorageNodeManager')->find(array('id'=>$PotentialStorageNodes)) AS $i => &$PotentialStorageNode) {
                if (!$PotentialStorageNode->isValid()) continue;
                if ($master && $PotentialStorageNode->get('storageGroupID') == $myStorageGroupID) continue;
                if (!file_exists($myAddItem)) {
                    $this->outall(_(" * Not syncing $objType between $itemType(s)"));
                    $this->outall(_(" | $objType Name: {$Obj->get(name)}"));
                    $this->outall(_(" | File or path cannot be reached"));
                    continue;
                }
                $this->FOGFTP
                    ->set('username',$PotentialStorageNode->get('user'))
                    ->set('password',$PotentialStorageNode->get('pass'))
                    ->set('host',$PotentialStorageNode->get('ip'));
                if (!$this->FOGFTP->connect()) {
                    $this->outall(_(" * Cannot connect to {$StorageNodeToSend->get(name)}"));
                    continue;
                }
                $nodename = $this->FOGFTP->get('name');
                $username = $this->FOGFTP->get('username');
                $password = $this->FOGFTP->get('password');
                $ip = $this->FOGFTP->get('host');
                $this->FOGFTP->close();
                $removeItem = sprintf('/%s%s',trim($PotentialStorageNode->get($getPathOfItemField),'/'),($master ? sprintf('/%s',$Obj->get($getFileOfItemField)) : ''));
                $limitmain = $this->byteconvert($StorageNode->get('bandwidth'));
                $limitsend = $this->byteconvert($PotentialStorageNode->get('bandwidth'));
                if ($limitmain > 0) $limitset = "set net:limit-total-rate 0:$limitmain;";
                if ($limitsend > 0) $limitset .= "set net:limit-rate 0:$limitsend;";
                $limit = $limitset;
                if (is_file($myAddItem)) {
                    $remItem = sprintf('%s/',dirname($removeItem));
                    $includeFile = sprintf('-i %s',basename($removeItem));
                } else {
                    $remItem = $removeItem;
                    $includeFile = null;
                }
                $date = $this->formatTime('','Ymd_His');
                $logname = "$this->log.transfer.$nodename.$date.log";
                if (!$i) $this->outall(_(' * Starting Sync Actions'));
                $this->startTasking("lftp -e 'set ftp:list-options -a;set net:max-retries 10;set net:timeout 30; $limit mirror -c -R --ignore-time $includeFile -vvv --exclude 'dev/' --exclude 'ssl/' --exclude 'CA/' --delete-first $myAddItem $remItem; exit' -u $username,$password $ip",$logname);
                unset($PotentialStorageNode);
            }
        }
    }
    public function startTasking($cmd,$logname) {
        $descriptor = array(0=>array('pipe','r'),1=>array('file',$logname,'w'),2=>array('file',$this->log,'a'));
        $this->procRef[] = @proc_open($cmd,$descriptor,$pipes);
        $this->procPipes[] = $pipes;
    }
    public function killAll($pid,$sig) {
        exec("ps -ef|awk '\$3 == '$pid' {print \$2}'",$output,$ret);
        if ($ret) return false;
        while (list(,$t) = each($output)) {
            if ($t != $pid) $this->killAll($t,$sig);
        }
        posix_kill($pid,$sig);
    }
    public function killTasking() {
        foreach ((array)$this->procRef AS $i => $procRef) {
            @fclose($this->procPipe[$i]);
            unset($this->procPipe[$i]);
            $running = 4;
            if ($this->isRunning($procRef)) {
                $running = 5;
                $pid = $this->getPID($procRef);
                if ($pid) $this->killAll($pid,SIGTERM);
                @proc_terminate($procRef,SIGTERM);
            }
            @proc_close($procRef);
            unset($procRef);
        }
        $this->procRef = $this->procPipes = array();
        return true;
    }
    public function getPID($procRef) {
        if (!$procRef) return false;
        $ar = proc_get_status($procRef);
        return $ar['pid'];
    }
    public function isRunning($procRef) {
        if (!$procRef) return false;
        $ar = proc_get_status($procRef);
        return $ar['running'];
    }
}
