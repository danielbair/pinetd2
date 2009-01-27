<?php

namespace Daemon\PMaild;

use pinetd\SQL;

class IMAP4_Client extends \pinetd\TCP\Client {
	protected $login = null;
	protected $info = null;
	protected $loggedin = false;
	protected $sql;
	protected $localConfig;
	protected $queryId = null;
	protected $selectedFolder = null;
	protected $uidmap = array();

	function __construct($fd, $peer, $parent, $protocol) {
		parent::__construct($fd, $peer, $parent, $protocol);
		$this->setMsgEnd("\r\n");
	}

	function welcomeUser() { // nothing to do
		return true;
	}

	protected function parseFetchParam($param) {
		// support for macros
		switch(strtoupper($param)) {
			case 'ALL': $param = '(FLAGS INTERNALDATE RFC822.SIZE ENVELOPE)'; break;
			case 'FAST': $param = '(FLAGS INTERNALDATE RFC822.SIZE)'; break;
			case 'FULL': $param = '(FLAGS INTERNALDATE RFC822.SIZE ENVELOPE BODY)'; break;
		}
		$result = array();
		$string = null;
		$reference = &$result;
		$len = strlen($param);
		$level = 0;
		$ref = array(0 => &$result);
		for($i=0; $i<$len;$i++) {
			$c = $param[$i];
			if ($c == '(') {
				$level++;
				$array = array();
				$ref[$level] = &$array;
				$reference[] = &$array;
				$reference = &$array;
				unset($array);
				continue;
			}
			if ($c == '[') {
				$level++;
				if (is_null($string)) throw new Exception('parse error');
				$array = array();
				$ref[$level] = &$array;
				$reference[$string] = &$array;
				$reference = &$array;
				unset($array);
				$string = null;
				continue;
			}
			if (($c == ')') || ($c == ']')) {
				$level--;
				if (!is_null($string)) $reference[] = $string;
				$string = null;
				$reference = &$ref[$level];
				continue;
			}
			if ($c == ' ') {
				if (is_null($string)) continue;
				$reference[] = $string;
				$string = null;
				continue;
			}
			$string .= $c;
		}
		if (!is_null($string)) $result[] = $string;
		if (is_array($result[0])) $result = $result[0];
		unset($result['parent']);
		return $result;
	}

	function imapParam($str, $label=null) {
		if (is_null($str)) return 'NIL';
		if (is_array($str)) {
			$res = '';
			foreach($str as $lbl => $var) {
				$res.=($res == ''?'':' ').$this->imapParam($var, $lbl);
			}
			if (is_string($label)) return $label.'['.$res.']';
			return '('.$res.')';
		}
		if ($str === '') return '""';
		if (strpos($str, "\n") !== false) {
			return '{'.strlen($str).'}'."\r\n".$str; // TODO: is this linebreak ok?
		}
		$add = addcslashes($str, '"\'');
		if (($add == $str) && ($str != 'NIL') && (strpos($str, ' ') === false)) return $str;
		return '"'.$add.'"';
	}

	function sendBanner() {
		$this->sendMsg('OK '.$this->IPC->getName().' IMAP4rev1 2001.305/pMaild on '.date(DATE_RFC2822));
		$this->localConfig = $this->IPC->getLocalConfig();
		return true;
	}
	protected function parseLine($lin) {
		$lin = rtrim($lin); // strip potential \r and \n
		$match = array();
		$res = preg_match_all('/([^" ]+)|("(([^\\\\"]|(\\\\")|(\\\\\\\\))*)")/', $lin, $match);
		$argv = array();
		foreach($match[0] as $idx=>$arg) {
			if (($arg[0] == '"') && (substr($arg, -1) == '"')) {
				$argv[] = preg_replace('/\\\\(.)/', '\\1', $match[3][$idx]);
				continue;
			}
			$argv[] = $arg;
		}
		$this->queryId = array_shift($argv);
		$cmd = '_cmd_'.strtolower($argv[0]);
		if (!method_exists($this, $cmd)) $cmd = '_cmd_default';
		$res = $this->$cmd($argv, $lin);
		$this->queryId = null;
		return $res;
	}

	public function sendMsg($msg, $id=null) {
		if (is_null($id)) $id = $this->queryId;
		if (is_null($id)) $id = '*';
		return parent::sendMsg($id.' '.$msg);
	}

	protected function updateUidMap() {
		// compute uidmap and uidnext
		$this->uidmap = array(0 => null);
		$pos = $this->selectedFolder;
		$req = 'SELECT `mailid` FROM `z'.$this->info['domainid'].'_mails` WHERE `userid` = \''.$this->sql->escape_string($this->info['account']->id).'\' ';
		$req.= 'AND `folder`=\''.$this->sql->escape_string($pos).'\' ';
		$req.= 'ORDER BY `mailid` ASC';
		$res = $this->sql->query($req);
		while($row = $res->fetch_assoc()) {
			$this->uidmap[] = $row['mailid'];
			$uidnext = $row['mailid'] + 1;
		}
	}

	function shutdown() {
		$this->sendMsg('BYE IMAP4 server is shutting down, please try again later', '*');
	}

	protected function identify($pass) { // login in $this->login
		$class = relativeclass($this, 'MTA\\Auth');
		$auth = new $class($this->localConfig);
		$this->loggedin = $auth->login($this->login, $pass, 'imap4');
		if (!$this->loggedin) return false;
		$this->login = $auth->getLogin();
		$info = $auth->getInfo();
		$this->info = $info;
		// link to MySQL
		$this->sql = SQL::Factory($this->localConfig['Storage']);
		return true;
	}

	protected function mailPath($uniq) {
		$path = $this->localConfig['Mails']['Path'].'/domains';
		if ($path[0] != '/') $path = PINETD_ROOT . '/' . $path; // make it absolute
		$id = $this->info['domainid'];
		$id = str_pad($id, 10, '0', STR_PAD_LEFT);
		$path .= '/' . substr($id, -1) . '/' . substr($id, -2) . '/' . $id;
		$id = $this->info['account']->id;
		$id = str_pad($id, 4, '0', STR_PAD_LEFT);
		$path .= '/' . substr($id, -1) . '/' . substr($id, -2) . '/' . $id;
		$path.='/'.$uniq;
		return $path;
	}

	function _cmd_default($argv, $lin) {
		$this->sendMsg('BAD Unknown command');
		var_dump($argv, $lin);
	}

	function _cmd_noop() {
		$this->sendMsg('OK NOOP completed');
	}

	function _cmd_capability() {
		$secure = true;
		if ($this->protocol == 'tcp') $secure=false;
		$this->sendMsg('CAPABILITY IMAP4REV1 '.($secure?'':'STARTTLS ').'X-NETSCAPE NAMESPACE MAILBOX-REFERRALS SCAN SORT THREAD=REFERENCES THREAD=ORDEREDSUBJECT MULTIAPPEND LOGIN-REFERRALS AUTH='.($secure?'LOGIN':'LOGINDISABLED'), '*');
		$this->sendMsg('OK CAPABILITY completed');
	}

	function _cmd_logout() {
		$this->sendMsg('BYE '.$this->IPC->getName().' IMAP4rev1 server says bye!', '*');
		$this->sendMsg('OK LOGOUT completed');
		$this->close();

		if ($this->loggedin) {
			// Extra: update mail_count and mail_quota
			try {
				$this->sql->query('UPDATE `z'.$this->info['domainid'].'_accounts` AS a SET `mail_count` = (SELECT COUNT(1) FROM `z'.$this->info['domainid'].'_mails` AS b WHERE a.`id` = b.`userid`) WHERE a.`id` = \''.$this->sql->escape_string($this->info['account']->id).'\'');
				$this->sql->query('UPDATE `z'.$this->info['domainid'].'_accounts` AS a SET `mail_quota` = (SELECT SUM(b.`size`) FROM `z'.$this->info['domainid'].'_mails` AS b WHERE a.`id` = b.`userid`) WHERE a.`id` = \''.$this->sql->escape_string($this->info['account']->id).'\'');
			} catch(Exception $e) {
				// ignore it
			}
		}
	}

	function _cmd_starttls() {
		if (!$this->IPC->hasTLS()) {
			$this->sendMsg('NO SSL not available');
			return;
		}
		if ($this->protocol != 'tcp') {
			$this->sendMsg('BAD STARTTLS only available in PLAIN mode. An encryption mode is already enabled');
			return;
		}
		$this->sendMsg('OK STARTTLS completed');
		// TODO: this call will lock, need a way to avoid from doing it without Fork
		if (!stream_socket_enable_crypto($this->fd, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
			$this->sendMsg('BYE TLS negociation failed!', '*');
			$this->close();
		}
		$this->protocol = 'tls';
	}

	function _cmd_login($argv) {
		// X LOGIN login password
		if ($this->loggedin) return $this->sendMsg('BAD Already logged in');
		if ($this->protocol == 'tcp') return $this->sendMsg('BAD Need SSL before logging in');
		$this->login = $argv[1];
		$pass = $argv[2];
		if (!$this->identify($pass)) {
			$this->sendMsg('NO Login or password are invalid.');
			return;
		}
		$this->sendMsg('OK LOGIN completed');
	}

	function _cmd_authenticate($argv) {
		if ($this->loggedin) return $this->sendMsg('BAD Already logged in');
		if ($this->protocol == 'tcp') return $this->sendMsg('BAD Need SSL before logging in');
		if (strtoupper($argv[1]) != 'LOGIN') {
			$this->sendMsg('BAD Unsupported auth method');
			return;
		}
		parent::sendMsg('+ '.base64_encode('User Name')); // avoid tag
		$res = $this->readLine();
		if ($res == '*') return $this->sendMsg('BAD AUTHENTICATE cancelled');
		$this->login = base64_decode($res);

		parent::sendMsg('+ '.base64_encode('Password')); // avoid tag
		$res = $this->readLine();
		if ($res == '*') return $this->sendMsg('BAD AUTHENTICATE cancelled');
		$pass = base64_decode($res);

		if(!$this->identify($pass)) {
			$this->sendMsg('NO AUTHENTICATE failed; login or password are invalid');
			return;
		}
		$this->sendMsg('OK AUTHENTICATE succeed');
	}

	function _cmd_namespace() {
		// * NAMESPACE (("" "/")("#mhinbox" NIL)("#mh/" "/")) (("~" "/")) (("#shared/" "/")("#ftp/" "/")("#news." ".")("#public/" "/"))
		// TODO: find some documentation and adapt this function
		// Documentation for namespaces : RFC2342
		if (!$this->loggedin) return $this->sendMsg('BAD Login needed');
		$this->sendMsg('NAMESPACE (("" "/")) NIL NIL', '*');
		$this->sendMsg('OK NAMESPACE completed');
	}

	function _cmd_lsub($argv) {
		if (!$this->loggedin) return $this->sendMsg('BAD Login needed');
		$namespace = $argv[1];
		$param = $argv[2];
		if ($namespace == '') $namespace = '/';
		if ($namespace != '/') {
			$this->sendMsg('NO Unknown namespace');
			return;
		}
		// TODO: Find doc and fix that according to correct process
		$this->sendMsg('LSUB () "/" INBOX', '*');
		$DAO_folders = $this->sql->DAO('z'.$this->info['domainid'].'_folders', 'id');
		$list = $DAO_folders->loadByField(array('account'=>$this->info['account']->id));
		// cache list
		$cache = array(
			0 => array(
				'id' => 0,
				'name' => 'INBOX',
				'parent' => null,
			),
		);
		foreach($list as $info) {
			$info['name'] = mb_convert_encoding($info['name'], 'UTF7-IMAP', 'UTF-8'); // convert UTF-8 -> modified UTF-7
			$cache[$info['id']] = $info;
		}
		// list folders in imap server
		foreach($list as $info) {
			$info = $cache[$info['id']];
			$name = $info['name'];
			$parent = $info['parent'];
			while(!is_null($parent)) {
				$info = $cache[$parent];
				$name = $info['name'].'/'.$name;
				$parent = $info['parent'];
			}
			$flags = '';
			foreach(explode(',', $info['flags']) as $f) $flags.=($flags==''?'':',').'\\'.ucfirst($f);
			$this->sendMsg('LSUB ('.$flags.') "/" "'.addslashes($name).'"', '*');
		}
		$this->sendMsg('OK LSUB completed');
	}

	function imapWildcard($pattern, $string) {
		$pattern = preg_quote($pattern, '#');
		$pattern = str_replace('\\*', '.*', $pattern);
		$pattern = str_replace('%', '[^/]*', $pattern);
		return preg_match('#^'.$pattern.'$#', $string);
	}

	function _cmd_list($argv) {
		if (!$this->loggedin) return $this->sendMsg('BAD Login needed');
		$reference = $argv[1];
		$param = $argv[2];
		if ($param == '') {
			$this->sendMsg('LIST (\NoSelect) "/" ""', '*');
			$this->sendMsg('OK LIST completed');
			return;
		}
		if ($reference == '') $reference = '/';
		$name = $param;
		$DAO_folders = $this->sql->DAO('z'.$this->info['domainid'].'_folders', 'id');
		$parent = null;
		if ($reference != '/') {
			foreach(explode('/', $reference) as $ref) {
				if ($ref === '') continue;
				if ((is_null($parent)) && ($ref == 'INBOX')) {
					$parent = 0;
					continue;
				}
				$cond = array('account' => $this->info['account']->id, 'parent' => $parent, 'name' => $ref);
				$result = $DAO_folders->loadByField($cond);
				if (!$result) {
					$this->sendMsg('NO folder not found');
					return;
				}
				$parent = $result[0]->parent;
			}
		}
		if (is_null($parent) && (fnmatch($param, 'INBOX'))) {
			$this->sendMsg('LIST () "'.$reference.'" INBOX', '*');
		}
		$cond = array('account' => $this->info['account']->id, 'parent' => $parent);
		// load whole tree, makes stuff easier - list should be recursive unless '%' is provided
		$list = array();
		// start at parent
		$fetch = array($parent);
		$done = array();
		while($fetch) {
			$id = array_pop($fetch);
			if (isset($done[$id])) continue; // infinite loop
			$done[$id] = true;
			$cond['parent'] = $id;
			$result = $DAO_folders->loadByField($cond);
			foreach($result as $folder) {
				if (isset($list[$folder->parent])) $folder->name = $list[$folder->parent]->name . '/' . $folder->name;
				$fetch[] = $folder->id;
				$list[$folder->id] = $folder;
			}
		}
		foreach($list as $res) {
			if (!$this->imapWildcard($param, $res->name)) continue;
			$name = mb_convert_encoding($res->name, 'UTF-8', 'UTF7-IMAP');
			if ((addslashes($name) != $name) || (strpos($name, ' ') !== false)) $name = '"'.addslashes($name).'"';
			$flags = '';
			foreach(explode(',', $res['flags']) as $f) $flags.=($flags==''?'':',').'\\'.ucfirst($f);
			$this->sendMsg('LIST ('.$flags.') "'.$reference.'" '.$name, '*');
		}
		$this->sendMsg('OK LIST completed');
	}

	function _cmd_select($argv) {
		if (!$this->loggedin) return $this->sendMsg('BAD Login needed');
		if (count($argv) != 2) {
			$this->sendMsg('BAD Please provide only one parameter to SELECT');
			return;
		}
		$box = mb_convert_encoding($argv[1], 'UTF-8', 'UTF7-IMAP,UTF-8'); // RFC says we should accept UTF-8
		$box = explode('/', $box);
		$pos = null;
		$DAO_folders = $this->sql->DAO('z'.$this->info['domainid'].'_folders', 'id');
		foreach($box as $name) {
			if ($name === '') continue;
			if (($name == 'INBOX') && (is_null($pos))) {
				$pos = 0;
				continue;
			}
			$result = $DAO_folders->loadByField(array('account' => $this->info['account']->id, 'name' => $name, 'parent' => $pos));
			if (!$result) {
				$this->sendMsg('NO No such mailbox');
				return;
			}
			$pos = $result[0]->id;
		}
		$flags = array_flip(explode(',', $result[0]->flags));
		if (isset($flags['noselect'])) return $this->sendMsg('NO This folder has \\Noselect flag');
		$this->selectedFolder = $pos;
		// TODO: find a way to do this without MySQL specific code
		$req = 'SELECT `flags`, COUNT(1) AS num FROM `z'.$this->info['domainid'].'_mails` WHERE `userid` = \''.$this->sql->escape_string($this->info['account']->id).'\' GROUP BY `flags`';
		$res = $this->sql->query($req);
		$total = 0;
		$recent = 0;
		$unseen = 0;
		while($row = $res->fetch_assoc()) {
			$flags = array_flip(explode(',', $row['flags']));
			if (isset($flags['recent'])) $recent+=$row['num'];
			$total += $row['num'];
		}
		$this->updateUidMap();

		if ($recent > 0) {
			// got a recent mail, fetch its ID
			$req = 'SELECT `mailid` FROM `z'.$this->info['domainid'].'_mails` WHERE `userid` = \''.$this->sql->escape_string($this->info['account']->id).'\' ';
			$req.= 'AND `folder`=\''.$this->sql->escape_string($pos).'\' AND FIND_IN_SET(\'recent\',`flags`)>0 ';
			$req.= 'ORDER BY `mailid` ASC LIMIT 1';
			$res = $this->sql->query($req);
			if ($res) $res = $res->fetch_assoc();
			if ($res) {
				$unseen = $res['mailid'];
				$unseen = array_search($unseen, $this->uidmap);
			}
		}


		// send response
		$this->sendMsg($total.' EXISTS', '*');
		$this->sendMsg($recent.' RECENT', '*');
		$this->sendMsg('OK [UIDVALIDITY '.$this->info['account']->id.'] UIDs valid', '*');
		$this->sendMsg('OK [UIDNEXT '.$uidnext.'] Predicted next UID', '*');
		$this->sendMsg('FLAGS (\Answered \Flagged \Deleted \Seen \Draft)', '*');
		$this->sendMsg('OK [PERMANENTFLAGS (\* \Answered \Flagged \Deleted \Draft \Seen)] Permanent flags', '*');
		if ($unseen) $this->sendMsg('OK [UNSEEN '.$unseen.'] Message '.$unseen.' is first recent', '*');
		if ($argv[0] == 'EXAMINE') {
			$this->sendMsg('OK [READ-ONLY] EXAMINE completed');
			return;
		}
		$this->sendMsg('OK [READ-WRITE] SELECT completed');
	}

	function _cmd_examine($argv) {
		$argv[0] = 'EXAMINE';
		return $this->_cmd_select($argv); // examine is the same, but read-only
	}

	function _cmd_create($argv) {
		$box = mb_convert_encoding($argv[1], 'UTF-8', 'UTF7-IMAP,UTF-8'); // RFC says we should accept UTF-8
		$box = explode('/', $box);
		$newbox = array_pop($box);
		$pos = null;
		$DAO_folders = $this->sql->DAO('z'.$this->info['domainid'].'_folders', 'id');
		foreach($box as $name) {
			if ($name === '') continue;
			if (($name == 'INBOX') && (is_null($pos))) {
				$pos = 0;
				continue;
			}
			$result = $DAO_folders->loadByField(array('account' => $this->info['account']->id, 'name' => $name, 'parent' => $pos));
			if (!$result) {
				$this->sendMsg('NO No such mailbox');
				return;
			}
			$pos = $result[0]->id;
		}
		if (is_null($pos) && ($newbox == 'INBOX')) {
			$this->sendMsg('NO Do not create INBOX, it already exists, damnit!');
			return;
		}
		$result = $DAO_folders->loadByField(array('account' => $this->info['account']->id, 'name' => $newbox, 'parent' => $pos));
		if ($result) {
			$result = $result[0];
			$flags = array_flip(explode(',', $result->flags));
			if (isset($flags['noselect'])) {
				$result->flags = ''; // clear flags
				$result->commit();
				$this->sendMsg('OK CREATE completed');
				return;
			}
			$this->sendMsg('NO Already exists');
			return;
		}
		$insert = array(
			'account' => $this->info['account']->id,
			'name' => $newbox,
			'parent' => $pos,
		);
		if (!$DAO_folders->insertValues($insert)) {
			$this->sendMsg('NO Unknown error');
			return;
		}
		$this->sendMsg('OK CREATE completed');
	}

	function _cmd_delete($argv) {
		$box = mb_convert_encoding($argv[1], 'UTF-8', 'UTF7-IMAP,UTF-8'); // RFC says we should accept UTF-8
		$box = explode('/', $box);
		$pos = null;
		$DAO_folders = $this->sql->DAO('z'.$this->info['domainid'].'_folders', 'id');
		foreach($box as $name) {
			if ($name === '') continue;
			if (($name == 'INBOX') && (is_null($pos))) {
				$pos = 0;
				continue;
			}
			$result = $DAO_folders->loadByField(array('account' => $this->info['account']->id, 'name' => $name, 'parent' => $pos));
			if (!$result) {
				$this->sendMsg('NO No such mailbox');
				return;
			}
			$pos = $result[0]->id;
		}
		if ($pos === 0) {
			// RFC says deleting INBOX is an error (RFC3501, 6.3.4)
			$this->sendMsg('NO Do not delete INBOX, where will I be able to put your mails?!');
			return;
		}
		if (is_null($pos)) {
			$this->sendMsg('NO hey man! Do not delete root, would you?');
			return;
		}
		// delete box content
		$this->sql->query('DELETE mh, m FROM `z'.$this->info['domainid'].'_mailheaders` AS mh, `z'.$this->info['domainid'].'_mails` AS m WHERE m.`parent` = \''.$this->sql->escape_string($pos).'\' AND m.`account` = \''.$this->sql->escape_string($this->info['account']->id).'\' AND m.mailid = mh.mailid');
		// check if box has childs
		$res = $DAO_folders->loadByField(array('account' => $this->info['account']->id, 'parent' => $pos));
		$result = $result[0]; // from the search loop
		if ($res) {
			$result->flags = 'noselect'; // put noselect flag
			$result->commit();
			$this->sendMsg('OK DELETE completed');
			return;
		}
		$result->delete();
		$this->sendMsg('OK DELETE completed');
	}

	function fetchMailByUid($uid, $param, $id = NULL) {
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');
		$DAO_mailheaders = $this->sql->DAO('z'.$this->info['domainid'].'_mailheaders', 'id');

		if (is_null($id)) $id = $uid;

		$result = $DAO_mails->loadByField(array('mailid' => $uid, 'userid' => $this->info['account']->id));
		if (!$result) return false;
		$mail = $result[0];
		$tmp_headers = $DAO_mailheaders->loadByField(array('mailid' => $uid, 'userid' => $this->info['account']->id));
		$headers = array();
		foreach($tmp_headers as $h) {
			$headers[strtolower($h->header)][] = $h;
		}
		$this->sendMsg($id.' FETCH '.$this->fetchParamByMail($mail, $headers, $param), '*');
		return true;
	}

	function fetchMailById($id, $param) {
		$DAO_mails = $this->sql->DAO('z'.$this->info['domainid'].'_mails', 'mailid');
		while(1) {
			// not in the current uidmap?
			if (!isset($this->uidmap[$id])) {
				return false;
			}
			$uid = $this->uidmap[$id];
			$result = $DAO_mails->loadByField(array('mailid' => $uid, 'userid' => $this->info['account']->id));
			if (!$result) {
				// update uid map, if we have a non-existant UID in our map that means something was deleted
				$this->updateUidMap();
				continue; // and retry lookup
			}
			return $this->fetchMailByUid($uid, $param, $id);
		}
	}

	function fetchParamByMail($mail, $headers, $param) {
		$file = $this->mailPath($mail->uniqname);
		$res = array();
		foreach($param as $id => $item) {
			if ((is_array($item)) && (is_int($id))) {
				$res .= $this->fetchParamByMail($mail, $item);
				continue;
			}
			$item_param = null;
			if (!is_int($id)) {
				$item_param = $item;
				$item = $id;
			}
			switch(strtoupper($item)) {
				case 'UID':
					$res[] = 'UID';
					$res[] = $mail->mailid;
					break;
				case 'ENVELOPE':
					$fields = array(
						'date' => 's', // string
						'subject' => 's', // string
						'from' => 'm', // list
						'sender' => 'm',
						'reply-to' => 'm',
						'to' => 'm',
						'cc' => 'm',
						'bcc' => 'm',
						'in-reply-to' => 'l',
						'message-id' => 's',
					);
					// load mail headers from SQL
					$envelope = array();
					foreach($fields as $head => $type) {
						if (!isset($headers[$head])) {
							$envelope[] = null;
							continue;
						}
						switch($type) {
							case 's':
								$envelope[] = $headers[$head][0]->content;
								break;
							case 'm':
								$tmp = array();
								foreach($headers[$head] as $h) {
									$infolist = imap_rfc822_parse_adrlist($h->content, '');
									foreach($infolist as $info) {
										if ($info->host === '') $info->host = null;
										$tmp[] = array($info->personal, $info->adl, $info->mailbox, $info->host);
									}
								}
								$envelope[] = $tmp;
								break;
							case 'l':
								$tmp = array();
								foreach($headers[$head] as $h) {
									$tmp[] = $h->content;
								}
								$envelope[] = $tmp;
								break;
							default:
								$envelope[] = $head;
								break;
						}
					}
					$res[] = 'ENVELOPE';
					$res[] = $envelope;
					break;
				case 'BODY':
					// TODO: clear "Recent" flag, and maybe add seen?
				case 'BODY.PEEK':
					$res_body = $this->fetchBody($mail, $item_param);
					foreach($res_body as $t => $v) {
						if (is_string($t)) {
							$res[$t] = $v;
							continue;
						}
						$res[] = $v;
					}
					break;
				case 'RFC822.SIZE': // TODO: determine if we should include headers in size
					$res[] = 'RFC822.SIZE';
					$res[] = filesize($file);
					break;
				case 'FLAGS':
					$flags = explode(',', $mail->flags);
					$f = array();
					foreach($flags as $flag) $f[] = '\\'.ucfirst($flag);
					$res[] = 'FLAGS';
					$res[] = $f;
					break;
				case 'INTERNALDATE':
					$res[] = 'INTERNALDATE';
					$res[] = date(DATE_RFC2822, filectime($file));
					break;
				default:
					var_dump($item, $item_param);
					$res[] = strtoupper($item);
					$res[] = NULL;
					break;
			}
		}
		return $this->imapParam($res);
	}

	function fetchBody($mail, $param) {
		$file = $this->mailPath($mail->uniqname);
		$len = sizeof($param);
		$var = array();
		$res = array();

		for($i=0;$i<$len;$i++) {
			$p = $param[$i];
			switch(strtoupper($p)) {
				case 'HEADER.FIELDS':
					$list = $param[++$i];
					foreach($list as &$ref) $ref = strtoupper($ref); // toupper
					unset($ref); // avoid overwrite
					$list = array_flip($list);
					$head = "";
					$add = false;

					// read file
					$fp = fopen($file, 'r'); // read headers
					if (!$fp) break;

					while(!feof($fp)) {
						$lin = fgets($fp);
						if (trim($lin) === '') break;
						if (($lin[0] == "\t") || ($lin[0] == ' ')) {
							if ($add) $head .= $lin;
							continue;
						}
						$add = false;
						$pos = strpos($lin, ':');
						if ($pos === false) continue;
						$h = strtoupper(rtrim(substr($lin, 0, $pos)));
						if (!isset($list[$h])) continue;
						$head .= $lin;
						$add = true;
					}
					$var[] = 'HEADER.FIELDS';
					$var[] = array_flip($list);
					$res[] = $head;
					break;
				case 'TEXT':
					// fetch body text
					// read file
					$fp = fopen($file, 'r'); // read headers
					if (!$fp) break;

					$str = '';
					$start = false;

					while(!feof($fp)) {
						$lin = fgets($fp);
						if (!$start) {	
							if (trim($lin) == '') $start = true;
							continue;
						}
						$str .= $lin;
					}
					$var[] = 'TEXT';
					$res[] = $str;
					break;
				default:
					var_dump('BODY UNKNOWN: '.$p);
			}
		}
		$var = array('BODY' => $var);
		foreach($res as $r) $var[] = $r;
		return $var;
	}
/*
A FETCH 1 (UID ENVELOPE BODY.PEEK[HEADER.FIELDS (Newsgroups Content-MD5 Content-Disposition Content-Language Content-Location Followup-To References)] INTERNALDATE RFC822.SIZE FLAGS)
* 1 FETCH (UID 1170 ENVELOPE ("9 Aug 2005 18:25:47 -0000" "New graal.net Player World Submitted" ((NIL NIL "noreply" "graal.net")) ((NIL NIL "noreply" "graal.net")) ((NIL NIL "noreply" "graal.net")) ((NIL NIL "MagicalTux" "online.fr")) NIL NIL NIL "<20050809182547.3404.qmail@europa13.legende.net>") BODY[HEADER.FIELDS ("NEWSGROUPS" "CONTENT-MD5" "CONTENT-DISPOSITION" "CONTENT-LANGUAGE" "CONTENT-LOCATION" "FOLLOWUP-TO" "REFERENCES")] {2}

 INTERNALDATE " 9-Aug-2005 20:07:37 +0000" RFC822.SIZE 1171 FLAGS (\Seen))
A OK FETCH completed

A FETCH 1 (UID)
* 1 FETCH (UID 1170)

A FETCH 1 (ENVELOPE)
* 1 FETCH (ENVELOPE ("9 Aug 2005 18:25:47 -0000" "New graal.net Player World Submitted" ((NIL NIL "noreply" "graal.net")) ((NIL NIL "noreply" "graal.net")) ((NIL NIL "noreply" "graal.net")) ((NIL NIL "MagicalTux" "online.fr")) NIL NIL NIL "<20050809182547.3404.qmail@europa13.legende.net>"))
A OK FETCH completed

A FETCH 1 BODY.PEEK[HEADER]
* 1 FETCH (BODY[HEADER] {567}
Return-Path: <noreply@graal.net>
Delivered-To: online.fr-MagicalTux@online.fr
Received: (qmail 29038 invoked from network); 9 Aug 2005 20:07:37 -0000
Received: from europa13.legende.net (194.5.30.13)
  by mrelay5-1.free.fr with SMTP; 9 Aug 2005 20:07:37 -0000
Received: (qmail 3405 invoked by uid 99); 9 Aug 2005 18:25:47 -0000
Date: 9 Aug 2005 18:25:47 -0000
Message-ID: <20050809182547.3404.qmail@europa13.legende.net>
To: MagicalTux@online.fr
From: <noreply@graal.net>
Subject: New graal.net Player World Submitted
Content-type: text/plain; charset=

)
A OK FETCH completed

*/
		// read it

	function _cmd_fetch($argv) {
		array_shift($argv); // FETCH
		$id = array_shift($argv); // might be "2:4"

		// parse param
		$param = implode(' ', $argv);
		// ok, let's parse param
		$param = $this->parseFetchParam($param);

		$last = null;
		while(strlen($id) > 0) {
			$pos = strpos($id, ':');
			$pos2 = strpos($id, ',');
			if (($pos === false) && ($pos2 === false)) {
				$this->fetchMailById($id, $param);
				break;
			}
			if ($pos === false) $pos = strlen($id);
			if ($pos2 === false) $pos2 = strlen($id);
			if ($pos < $pos2) {
				// got an interval. NB: 1:3:5 is impossible, must be 1:3,5 or something like that
				$start = substr($id, 0, $pos);
				$end = substr($id, $pos+1, $pos2 - $pos - 1);
				$id = substr($id, $pos2+1);
				if ($end == '*') {
					$i = $start;
					while($this->fetchMailById($i++, $param));
					continue;
				}
				for($i=$start; $i <= $end; $i++) {
					$this->fetchMailById($i, $param);
				}
			} else {
				$i = substr($id, 0, $pos2);
				$id = substr($id, $pos2+1);
				$this->fetchMailById($i, $param);
			}
		}
		$this->sendMsg('OK FETCH completed');
	}

//A00008 UID FETCH 1:* (FLAGS RFC822.SIZE INTERNALDATE BODY.PEEK[HEADER.FIELDS (DATE FROM TO CC SUBJECT REFERENCES IN-REPLY-TO MESSAGE-ID MIME-VERSION CONTENT-TYPE X-MAILING-LIST X-LOOP LIST-ID LIST-POST MAILING-LIST ORIGINATOR X-LIST SENDER RETURN-PATH X-BEENTHERE)])
	function _cmd_uid($argv) {
		array_shift($argv); // UID
		$fetch = array_shift($argv); // FETCH
		
		// UID FETCH, UID SEARCH, UID STORE
		if (strtoupper($fetch) != 'FETCH') {
			$this->sendMsg('BAD Should have been "UID FETCH"');
			return;
		}

		// TODO: continue here!
	}
}


