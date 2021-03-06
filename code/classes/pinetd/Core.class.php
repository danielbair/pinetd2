<?php
/*   Portable INET daemon v2 in PHP
 *   Copyright (C) 2007 Mark Karpeles <mark@kinoko.fr>
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program; if not, write to the Free Software
 *   Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * \file Core.class.php
 * \brief Contains core code for pinetd
 */


namespace pinetd;

/**
 * \class Core
 * \brief Core code for pinetd
 */
class Core {
	/**
	 * \brief Contains SimpleXML object for current config
	 *
	 * This is the configuration file, as a SimpleXML object.
	 */
	private $config;

	/**
	 * \brief List of currently running daemons
	 *
	 * Currently running daemons, in a pid-indexed array
	 */
	private $daemons;
	/**
	 * \brief List of active sockets
	 *
	 * This list contains all active sockets and function to be called if something
	 * happens on this socket.
	 */
	private $fdlist = array();

	/**
	 * \brief List of currently enabled ports
	 */
	private $ports = array();

	private $transport_engine;
	private $bc_flood = array(); // broadcast flood detector

	public function __construct() {
		$this->config = ConfigManager::invoke();
		$this->transport_engine = new TransportEngine($this);
		$this->daemons = array();
		pcntl_signal(SIGTERM, array(&$this, 'sighandler'), false);
		pcntl_signal(SIGINT, array(&$this, 'sighandler'), false);
		pcntl_signal(SIGCHLD, SIG_DFL, false);

		if (file_exists(PINETD_ROOT.'/control.sock')) unlink(PINETD_ROOT.'/control.sock');
		$master = stream_socket_server('unix://'.PINETD_ROOT.'/control.sock', $errno, $errstr);
		if ($master) {
			$this->registerSocketWait($master, array($this, 'newMasterConnection'), $d = array($master));
		} else {
			Logger::log(Logger::LOG_WARN, 'Could not create master socket: ['.$errno.'] '.$errstr);
		}
	}

	public function newMasterConnection($sock) {
		$new = stream_socket_accept($sock, 0, $peer);
		$no = '';
		$this->registerSocketWait($new, array($this, 'masterData'), $d = array($new));
	}

	public function masterData($sock) {
		if (!isset($this->fdlist[(int)$sock])) return;

		$data = fread($sock, 65535);
		if (($data === false) || ($data === '')) {
			$this->removeSocket($sock);
			fclose($sock);
			return;
		}
		$peer = &$this->fdlist[(int)$sock];
		if (!isset($peer['buffer'])) $peer['buffer'] = '';
		$buffer = &$peer['buffer'];
		$buffer .= $data;

		while(1) {
			if (strlen($buffer) < 4) break;
			list(,$len) = unpack('N', $buffer);
			if ($len > strlen($buffer)) break;
			$packet = substr($buffer, 4, $len-4);
			$buffer = substr($buffer, $len);
			$packet = unserialize($packet);
			if (!is_array($packet)) continue;
			$packet['sock'] = $sock;
			$this->masterPacket($packet);
		}
	}

	public function masterPacket(array $packet) {
		switch(strtolower($packet['cmd'])) {
			case 'getpid':
				$this->masterReply($packet, array('type' => 'pid', 'pid' => getmypid()));
				break;
			case 'getversion':
				$this->masterReply($packet, array('type' => 'version', 'version' => PINETD_VERSION));
				break;
			case 'list_daemons':
				$list = array();
				foreach($this->daemons as $id => $dat) {
					unset($dat['IPC']);
					unset($dat['daemon']);
					$dat['socket'] = (int)$dat['socket'];
					$list[$id] = $dat;
				}
				$this->masterReply($packet, array('type' => 'daemons', 'daemons' => $list));
				break;
			case 'stop': // STOP DAEMON
				Logger::log(Logger::LOG_INFO, 'Stop command received via control socket, stopping...');
				$this->masterReply($packet, array('type' => 'ack', 'ack' => 'stop'));
				foreach(array_keys($this->daemons) as $port) {
					$this->killDaemon($port, 800);
				}
				$exp = time() + 12;
				while(count($this->daemons) > 0) {
					$this->checkRunning();
					$this->receiveStopped();
					$this->childrenStatus();
					$this->readIPCs(200000);
					foreach($this->daemons as $id=>$dat) {
						if ($dat['status'] == 'Z') {
							$this->masterReply($packet, array('type' => 'finished', 'finished' => $id));
							unset($this->daemons[$id]);
						}
					}
					if ($exp <= time()) {
						Logger::log(Logger::LOG_WARN, 'Not all processes finished after 12 seconds');
						$this->masterReply($packet, array('type' => 'notify', 'notify' => 'stop_soft_failure'));
						break;
					}
				}
				Logger::log(Logger::LOG_INFO, 'Good bye!');
				$this->masterReply($packet, array('type' => 'ack', 'ack' => 'stop_finished'));
				exit;
			default:
				$this->masterReply($packet, array('exception' => 'No such command!'));
				break;
		}
	}

	public function masterReply($packet, array $data) {
		if (isset($packet['seq'])) $data['seq'] = $packet['seq'];
		$data = serialize($data);
		return fwrite($packet['sock'], pack('N', strlen($data)+4).$data);
	}

	/**
	 * \brief load a certificate for a given certificate name
	 * \param $SSL string Name of the SSL certificate to load
	 * \return array SSL descriptor
	 */
	public function loadCertificate($SSL) {
		foreach($this->config->SSL->Certificate as $node) {
			if ($node['name'] != $SSL) continue;
			$options = array();
			foreach($node->Option as $opt) {
				if ($opt['Disabled']) continue;
				$val = (string)$opt['Value'];
				if ($val === 'true') $val = true;
				if ($val === 'false') $val = false;
				$var = (string)$opt['name'];
				switch($var) {
					case 'cafile':
					case 'local_cert':
						$val = PINETD_ROOT . '/ssl/' . $val;
						break;
				}
				$options[$var] = $val;
			}
			return $options;
		}
		return null;
	}

	public function _ChildIPC_loadCertificate(&$daemon, $SSL) {
		return $this->loadCertificate($SSL);
	}

	public function createPort($port, &$class) {
		if (isset($this->ports[$port])) return false;
		$this->ports[$port] = array('type' => 'class', 'class' => &$class);
		return true;
	}

	public function routePortReply($reply, $is_exception = false) {
		$next = array_pop($reply[1]);
		if (!isset($this->fdlist[$next])) return; // ?!

		$key = $this->fdlist[$next]['key'];
		$daemon = &$this->daemons[$key];
		
		$code = IPC::RES_CALLPORT;
		if ($is_exception) $code = IPC::RES_CALLPORT_EXCEPT;
		$daemon['IPC']->sendcmd($code, $reply);
	}

	public function openPort($port) {
		if (!isset($this->ports[$port])) return NULL;

		return $this->ports[$port]['class'];
	}

	public function callPort($call) {
		// ok, determine where we should put this call
		if (!isset($this->ports[$call[0]])) {
			// port does not exists => die!
			$exception = array(
				$call[0],
				$call[1],
				'Requested port '.$call[0].' does not exists!',
			);
			$this->routePortReply($exception, true);
			return;
		}
		$call[1][] = '@parent';
		$class = &$this->ports[$call[0]]['class']; // at this point, ports are only class type
		if ($class instanceof IPC) {
			$class->sendcmd(IPC::CMD_CALLPORT, $call);
		} else {
			$method = $call[2];
			try {
				$res = call_user_func_array(array($class, $method), $call[3]);
			} catch(\Exception $e) {
				$exception = array(
					$call[0],
					$call[1],
					$e->getMessage(),
				);
				$this->routePortReply($exception, true);
				return;
			}

			$result = array(
				$call[0],
				$call[1],
				$res,
			);
			$this->routePortReply($result);
		}
	}

	public function broadcast($code, $data = null, $except = 0) {
		foreach($this->fdlist as $id => $info) {
			if ($id == $except) continue;
			if ($info['type'] != 'daemon') continue;
			$this->daemons[$info['key']]['IPC']->broadcast_up($code, $data);
		}
	}

	public function registerSocketWait($socket, $callback, &$data) {
		$this->fdlist[(int)$socket] = array('type'=>'callback', 'fd'=>$socket, 'callback'=>$callback, 'data'=>&$data);
	}

	public function removeSocket($fd) {
		unset($this->fdlist[(int)$fd]);
	}

	public function sighandler($signal) {
		switch($signal) {
			case SIGTERM:
			case SIGINT:
				Logger::log(Logger::LOG_INFO, 'Ending signal received, killing children...');
				foreach(array_keys($this->daemons) as $port) {
					$this->killDaemon($port, 800);
				}
				$exp = time() + 12;
				while(count($this->daemons) > 0) {
					$this->checkRunning();
					$this->receiveStopped();
					$this->childrenStatus();
					$this->readIPCs(200000);
					foreach($this->daemons as $id=>$dat) {
						if ($dat['status'] == 'Z') unset($this->daemons[$id]);
					}
					if ($exp <= time()) {
						Logger::log(Logger::LOG_WARN, 'Not all processes finished after 12 seconds');
						break;
					}
				}
				Logger::log(Logger::LOG_INFO, 'Good bye!');
				exit;
			#
		}
	}

	private function loadProcessDaemon($port, $node) {
		return $this->loadDaemon($port, $node, 'Process');
	}

	private function loadTCPDaemon($port, $node) {
		return $this->loadDaemon($port, $node, 'TCP');
	}
	
	private function loadUDPDaemon($port, $node) {
		return $this->loadDaemon($port, $node, 'UDP');
	}

	private function loadDaemon($port, $node, $type) {
		$key = $this->makeDaemonKey($node, $type);
		// determine HERE if we should fork...
		$daemon = &$this->daemons[$key];
		$good_keys = array('Type' => 1, 'Daemon'=>1, 'SSL' => 1, 'Port' => 1, 'Ip' => 1, 'Service' => 1);
		foreach(array_keys($daemon) as $_key) {
			if (!isset($good_keys[$_key])) unset($daemon[$_key]);
		}
		if (!$daemon['Service']) $daemon['Service'] = 'Base';
		$class = 'Daemon\\'.$daemon['Daemon'].'\\'.$daemon['Service'];
		if ((isset($this->config->Global->Security->Fork)) && PINETD_CAN_FORK) {
			// prepare an IPC
			$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
			if (is_array($pair)) {
				$pid = pcntl_fork();
				if ($pid > 0) {
					SQL::parentForked();
					// parent, record infos about this child
					$this->daemons[$key]['pid'] = $pid;
					$this->daemons[$key]['socket'] = $pair[0];
					$this->daemons[$key]['IPC'] = new IPC($pair[0], false, $this, $this);
					$this->daemons[$key]['status'] = 'R'; // running
					$this->fdlist[$pair[0]] = array('type'=>'daemon', 'port'=>$port, 'key' => $key,'fd'=>$pair[0]);
					fclose($pair[1]);
					return true;
				} elseif ($pid == 0) {
					SQL::forked();
					Timer::reset();
					fclose($pair[0]);
					pcntl_signal(SIGTERM, SIG_DFL, false);
					pcntl_signal(SIGINT, SIG_IGN, false); // fix against Ctrl+C
					pcntl_signal(SIGCHLD, SIG_DFL, false);
					// cleanup
					foreach($this->fdlist as $dat) fclose($dat['fd']);
					$this->fdlist = array();
					$IPC = new IPC($pair[1], true, $this, $this);
					$IPC->ping();
					Logger::setIPC($IPC);
					try {
						$daemon = new $class($port, $this->daemons[$key], $IPC, $node);
						$IPC->setParent($daemon);
						$daemon->mainLoop();
					} catch(\Exception $e) {
						$IPC->Exception($e);
						exit;
					}
					$IPC->Error('Unexpected end of program!', 60);
					exit;
				}
				fclose($pair[0]);
				fclose($pair[1]);
			}
			// if an error occured here, we fallback to no-fork method
		}
		// invoke the process in local scope
		try {
			$this->daemons[$key]['daemon'] = new $class($port, $this->daemons[$key], $this, $node);
			$this->daemons[$key]['status'] = 'I'; // Invoked (nofork)
		} catch(\Exception $e) {
			$this->daemons[$key]['status'] = 'Z';
			$this->daemons[$key]['deadline'] = time() + 60;
			Logger::log(Logger::LOG_ERR, 'From daemon on '.$key.': '.$e->getMessage());
		}
	}

	protected function makeDaemonKey($daemon, $type) {
		if(isset($daemon['Port'])) {
			$ip = (string)$this->config->Global->Network->Bind->Ip;
			if (isset($daemon['Ip'])) $ip = $daemon['Ip'];
			$port = '['.$ip.']:'.(int)$daemon['Port'];
		} else {
			$port = $daemon['Daemon'] . '::' . $daemon['Service'];
			if (isset($daemon['Ip'])) $port.='::'.$daemon['Ip'];
		}
		return $port . '/' .strtolower($type);
	}

	function startMissing() {
		foreach($this->config->Processes->children() as $Type => $Entry) {
			$data = array();
			foreach($Entry->attributes() as $attr => $aval) $data[$attr] = (string)$aval;
			$offset = (int)$this->config->Processes['PortOffset'];
			$data['Port'] += $offset;
			$data['Type'] = $Type;
			$key = $this->makeDaemonKey($Entry, $Type);
			if (isset($this->daemons[$key]))
				continue; // no care
			$this->daemons[$key] = $data;
			$startfunc = 'load'.$Type.'Daemon';
			$this->$startfunc((int)$data['Port'], $Entry);
		}
	}

	function checkRunning() {
		foreach($this->daemons as $key => $data) {
			// TODO: do something
		}
	}

	public function Error($errstr) {
		throw new \Exception($errstr);
	}

	public function _ChildIPC_killSelf(&$daemon) {
		// mark it "to be killed"
		Logger::log(Logger::LOG_DEBUG, 'pinetd\\Core\\_ChildIPC_killSelf() called for child on port #'.$daemon['Port']);
		if (
				($daemon['status'] != 'R') &&
				($daemon['status'] != 'T')
				)
			return;
		$daemon['IPC']->stop();
		if (!isset($daemon['kill']))
			$daemon['kill'] = time() + 5;
		$daemon['status'] = 'K';
	}

	private function killDaemon($key, $timeout) {
		// kill it!
		if (!isset($this->daemons[$key])) throw new \Exception('Unknown daemon '.$key);
		if ($this->daemons[$key]['status'] == 'I') {
			// not forked
			$this->daemons[$key]['daemon']->shutdown();
			$this->daemons[$key]['status'] = 'Z';
			return;
		}
		$this->removePorts($this->daemons[$key]['IPC']);
		if (
				($this->daemons[$key]['status'] != 'R') && 
				($this->daemons[$key]['status'] != 'K') &&
				($this->daemons[$key]['status'] != 'T')
				)
			return;
		if (posix_kill($this->daemons[$key]['pid'], 0)) {
			// still running
			$this->daemons[$key]['IPC']->stop();
			if (!isset($this->daemons[$key]['kill']))
				$this->daemons[$key]['kill'] = time() + 5;
			$this->daemons[$key]['status'] = 'K'; // kill
		} else {
			$this->daemons[$key]['status'] = 'Z'; // zombie
			@fclose($this->daemons[$key]['socket']); // make sure this is closed
			unset($this->fdlist[$this->daemons[$key]['socket']]);
		}
		if (!isset($this->daemons[$key]['deadline']))
			$this->daemons[$key]['deadline'] = time() + $timeout;
	}

	function IPCDied($fd) {
		if (!isset($this->fdlist[$fd])) return; // can't do anything about this
		switch($this->fdlist[$fd]['type']) {
			case 'daemon':
				$key = $this->fdlist[$fd]['key'];
				if ($this->daemons[$key]['status'] == 'R')
					Logger::log(Logger::LOG_DEBUG, 'IPC died on '.$key);
				fclose($fd);
				unset($this->fdlist[$fd]);
				$this->killDaemon($key, 10);
				break;
		}
	}

	private function removePorts($class) {
		if (!is_object($class)) return false;
		foreach($this->ports as $port => &$info) {
			if ($info['type'] != 'class') continue;
			if ($info['class'] === $class)
				unset($this->ports[$port]);
		}
		return true;
	}

	private function receiveStopped() {
		if (!PINETD_CAN_FORK) return;
		if (count($this->daemons) == 0) return; // nothing to do
		$res = pcntl_wait($status, WNOHANG);
		if ($res == -1) return; // something bad happened
		if ($res == 0) return; // no process terminated
		// search what ended
		$ended = null;
		foreach($this->daemons as $key => $dat) {
			if ($dat['pid'] == $res) {
				$ended = $key;
				break;
			}
		}
		if (is_null($ended)) return; // we do not know what ended
		if (pcntl_wifexited($status)) {
			$code = pcntl_wexitstatus($status);
			Logger::log(Logger::LOG_INFO, 'Child with pid #'.$res.' on ['.$key.'] exited');
			$this->killDaemon($key, 10);
			return;
		}
		if (pcntl_wifstopped($status)) {
			Logger::log(Logger::LOG_INFO, 'Waking up stopped child on pid '.$res);
			posix_kill($res, SIGCONT);
			return;
		}
		if (pcntl_wifsignaled($status)) {
			$signal = pcntl_wtermsig($status);
			$const = get_defined_constants(true);
			$const = $const['pcntl'];
			foreach($const as $var => $val) {
				if (substr($var, 0, 3) != 'SIG') continue;
				if (substr($var, 0, 4) == 'SIG_') continue;
				if ($val != $signal) continue;
				$signal = $var;
				break;
			}
			Logger::log(Logger::LOG_INFO, 'Child with pid #'.$res.' on '.$key.' died due to signal '.$signal);
			$this->killDaemon($key, 10);
		}
	}

	function readIPCs($timeout) {
		// build $r
		$r = array();
		foreach($this->fdlist as $dat) $r[] = $dat['fd'];
		$res = @stream_select($r, $w = null, $e = null, 0, $timeout);
		pcntl_signal_dispatch();
		if (($res == 0) && (count($r) > 0)) $res = count($r);
		if ($res > 0) {
			foreach($r as $fd) {
				switch($this->fdlist[$fd]['type']) {
					case 'daemon':
						// added if check for failing pinetd at customer place. TODO Why is this happening?
						if ($this->daemons[$this->fdlist[(int)$fd]['key']]['IPC'])
							$this->daemons[$this->fdlist[(int)$fd]['key']]['IPC']->run($this->daemons[$this->fdlist[(int)$fd]['key']], $fd);
						break;
					case 'callback':
						$info = &$this->fdlist[$fd];
						call_user_func_array($info['callback'], $info['data']);
						break;
				}
			}
		}
	}

	function childrenStatus() {
		$now = time();
		foreach($this->daemons as $key => &$data) {
			switch($data['status']) {
				case 'R': // running (forked)
				case 'I': // invoked (not forked)
					break;
				case 'K': // to kill
					if ($data['kill'] <= $now) {
						posix_kill($data['pid'], SIGTERM); // die!
						$data['status'] = 'T';
						$data['kill'] = time()+2;
					}
					break;
				case 'T': // terminating
					if ($data['kill'] <= $now) {
						posix_kill($data['pid'], SIGKILL); // DIE!!
						$data['status'] = 'Z';
						break;
					}
				case 'Z': // zombie
					if (!isset($data['deadline'])) {
						unset($this->daemons[$key]);
						break;
					}
					if ($data['deadline'] > $now) break;

					// Restart this daemon
					foreach($this->config->Processes->children() as $Type => $Entry) {
						$tmpkey = $this->makeDaemonKey($Entry, $Type);
						if ($tmpkey != $key) continue; // we don't want to start this one

						$startfunc = 'load'.$Type.'Daemon';
						$this->$startfunc($tmpport, $Entry);
						break; // ok, finished
					}
					break;
				default:
					echo 'UNKNOWN STATUS '.$data['status']."\n";
				#
			}
		}
	}
	
	function mainLoop() {
		while(1) {
			$this->checkRunning();
			$this->startMissing();
			$this->receiveStopped(); // waitpid
			$this->childrenStatus();
			$this->readIPCs(200000);
			Timer::processTimers();
		}
	}
}


