<?php
/**
 * @version $Id: view.php 1666 2010-01-15 10:49:12Z mahagr $
 * Kunena Component
 * @package Kunena
 *
 * @Copyright (C) 2008 - 2010 Kunena Team All rights reserved
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 * @link http://www.kunena.com
 *
 **/
defined ( '_JEXEC' ) or die ();

class CKunenaLatestX {
	public $allow = 0;

	function __construct($func, $page = 0) {
		$this->func = $func;
		$this->catid = 0;
		$this->page = $page < 1 ? 1 : $page;

		$this->db = JFactory::getDBO ();
		$this->user = $this->my = JFactory::getUser ();
		$this->session = CKunenaSession::getInstance ();
		$this->config = CKunenaConfig::getInstance ();

		$this->prevCheck = $this->session->lasttime;

		$this->app = & JFactory::getApplication ();
		$this->document = & JFactory::getDocument ();

		$this->show_list_time = JRequest::getInt ( 'sel', 720 );

		// My latest is only available to users
		if (! $this->user->id && $func == "mylatest") {
			return;
		}

		$this->allow = 1;

		$this->tabclass = array ("sectiontableentry1", "sectiontableentry2" );

		if (! $this->my->id && $this->show_list_time == 0) {
			$this->show_list_time = "720";
		}

		//start the latest x
		if ($this->show_list_time == 0) {
			$this->querytime = ($this->prevCheck - $this->config->fbsessiontimeout); //move 30 minutes back to compensate for expired sessions
		} else {
			//Time translation
			$back_time = $this->show_list_time * 3600; //hours*(mins*secs)
			$this->querytime = time () - $back_time;
		}

		$this->threads_per_page = $this->config->threads_per_page;
	}

	function _common() {
		$this->totalpages = ceil ( $this->total / $this->threads_per_page );

		//meta description and keywords
		$metaKeys = kunena_htmlspecialchars ( stripslashes ( _KUNENA_ALL_DISCUSSIONS . ", {$this->config->board_title}, " . $this->app->getCfg ( 'sitename' ) ) );
		$metaDesc = kunena_htmlspecialchars ( stripslashes ( _KUNENA_ALL_DISCUSSIONS . " ({$this->page}/{$this->totalpages}) - {$this->config->board_title}" ) );

		$cur = $this->document->get ( 'description' );
		$metaDesc = $cur . '. ' . $metaDesc;
		$this->document = & JFactory::getDocument ();
		$this->document->setMetadata ( 'robots', 'noindex, follow' );
		$this->document->setMetadata ( 'keywords', $metaKeys );
		$this->document->setDescription ( $metaDesc );

		$idstr = @join ( ",", $this->threadids );

		$this->messages = array ();

		if (count ( $this->threadids ) > 0) {
			$query = "SELECT a.*, j.id AS userid, t.message AS messagetext, l.myfavorite, l.favcount, l.attachmesid,
			l.msgcount, l.lastid, l.lastid AS lastread, 0 AS unread, u.avatar, c.id AS catid, c.name AS catname, c.class_sfx
		FROM (
			SELECT m.thread, MAX(f.userid IS NOT null AND f.userid='{$this->user->id}') AS myfavorite, COUNT(DISTINCT f.userid) AS favcount, COUNT(a.mesid) AS attachmesid,
				COUNT(DISTINCT m.id) AS msgcount, MAX(m.id) AS lastid, MAX(m.time) AS lasttime
			FROM #__fb_messages AS m";
			if ($this->config->allowfavorites) $query .= " LEFT JOIN #__fb_favorites AS f ON f.thread = m.thread";
			else $query .= " LEFT JOIN (SELECT 0 AS userid, 0 AS myfavorite) AS f ON 1";
			$query .= "
			LEFT JOIN #__fb_attachments AS a ON a.mesid = m.thread
			WHERE m.hold='0' AND m.moved='0' AND m.thread IN ({$idstr})
			GROUP BY thread
		) AS l
		INNER JOIN #__fb_messages AS a ON a.thread = l.thread
		INNER JOIN #__fb_messages_text AS t ON a.thread = t.mesid
		LEFT JOIN #__users AS j ON j.id = a.userid
		LEFT JOIN #__fb_users AS u ON u.userid = j.id
		LEFT JOIN #__fb_categories AS c ON c.id = a.catid
		WHERE (a.parent='0' OR a.id=l.lastid)
		ORDER BY {$this->order}";

			$this->db->setQuery ( $query );
			$messagelist = $this->db->loadObjectList ();
			check_dberror ( "Unable to load messages." );
			foreach ( $messagelist as $message ) {
				$this->messagetext [$message->id] = JString::substr ( smile::purify ( $message->messagetext ), 0, 500 );
				if ($message->parent == 0) {
					$this->messages [$message->id] = $message;
					$this->last_reply [$message->id] = $message;
					$routerlist [$message->id] = $message->subject;
				} else {
					$this->last_reply [$message->thread] = $message;
				}
			}
			include_once (KUNENA_PATH . DS . 'router.php');
			KunenaRouter::loadMessages ( $routerlist );

			if ($this->config->shownew && $this->my->id) {
				$readlist = '0' . $this->session->readtopics;
				$this->db->setQuery ( "SELECT thread, MIN(id) AS lastread, SUM(1) AS unread FROM #__fb_messages " . "WHERE hold='0' AND moved='0' AND thread NOT IN ({$readlist}) AND thread IN ({$idstr}) AND time>'{$this->prevCheck}' GROUP BY thread" );
				$msgidlist = $this->db->loadObjectList ();
				check_dberror ( "Unable to get unread messages count and first id." );

				foreach ( $msgidlist as $msgid ) {
					$this->messages[$msgid->thread]->lastread = $msgid->lastread;
					$this->messages[$msgid->thread]->unread = $msgid->unread;
				}
			}
		}
	}

	function getMyLatest($all = true) {
		if (isset($this->total)) return;
		$this->document->setTitle ( _KUNENA_MY_DISCUSSIONS . ' - ' . stripslashes ( $this->config->board_title ) );
		$query = "SELECT COUNT(DISTINCT m.thread) FROM (";
		if ($all) $query .= "
			SELECT thread, 0 AS fav
			FROM #__fb_messages
			WHERE userid='{$this->user->id}' AND moved='0' AND hold='0' AND catid IN ({$this->session->allowed})
			GROUP BY thread
		UNION ALL";
		$query .= "
			SELECT thread, 1 AS fav FROM #__fb_favorites WHERE userid='{$this->user->id}'
		) AS t
		INNER JOIN #__fb_messages AS m ON m.id=t.thread
		WHERE m.moved='0' AND m.hold='0' AND m.catid IN ({$this->session->allowed})";

		$this->db->setQuery ( $query );
		$this->total = ( int ) $this->db->loadResult ();
		check_dberror ( 'Unable to count total threads' );
		$offset = ($this->page - 1) * $this->threads_per_page;

		$this->order = "myfavorite DESC, lastid DESC";
		$query = "SELECT m.thread, MAX(m.id) as lastid, MAX(t.fav) AS myfavorite FROM (";
		if ($all) $query .= "
			SELECT thread, 0 AS fav
			FROM #__fb_messages
			WHERE userid='{$this->user->id}' AND moved='0' AND hold='0' AND catid IN ({$this->session->allowed})
			GROUP BY thread
		UNION ALL";
		$query .= "
			SELECT thread, 1 AS fav FROM #__fb_favorites WHERE userid='{$this->user->id}'
		) AS t
		INNER JOIN #__fb_messages AS m ON m.id=t.thread
		WHERE m.moved='0' AND m.hold='0' AND m.catid IN ({$this->session->allowed})
		GROUP BY thread
		ORDER BY {$this->order}";

		$this->db->setQuery ( $query, $offset, $this->threads_per_page );
		$this->threadids = $this->db->loadResultArray ();
		check_dberror ( "Unable to load thread list." );
		$this->_common();
	}

	function getSubscriptions() {
		if (isset($this->total)) return;
		$this->document->setTitle ( _KUNENA_MY_DISCUSSIONS . ' - ' . stripslashes ( $this->config->board_title ) );
		$query = "SELECT COUNT(*)
		FROM #__fb_subscriptions AS t
		INNER JOIN #__fb_messages AS m ON m.id=t.thread
		WHERE t.userid='{$this->user->id}' AND m.moved='0' AND m.hold='0' AND m.catid IN ({$this->session->allowed})";

		$this->db->setQuery ( $query );
		$this->total = ( int ) $this->db->loadResult ();
		check_dberror ( 'Unable to count total threads' );
		$offset = ($this->page - 1) * $this->threads_per_page;

		$this->order = "lastid DESC";
		$query = "SELECT m.thread, MAX(mm.id) as lastid
		FROM #__fb_subscriptions AS t
		INNER JOIN #__fb_messages AS m ON m.id=t.thread
		INNER JOIN #__fb_messages AS mm ON mm.thread=t.thread AND m.hold='0'
		WHERE t.userid='{$this->user->id}' AND m.moved='0' AND m.hold='0' AND m.catid IN ({$this->session->allowed})
		GROUP BY thread
		ORDER BY {$this->order}";

		$this->db->setQuery ( $query, $offset, $this->threads_per_page );
		$this->threadids = $this->db->loadResultArray ();
		check_dberror ( "Unable to load thread list." );
		$this->_common();
	}

	function getLatest() {
		if (isset($this->total)) return;
		$lookcats = explode ( ',', $this->config->latestcategory );
		$catlist = array ();
		$latestcats = '';
		foreach ( $lookcats as $catnum ) {
			if (( int ) $catnum > 0)
				$catlist [] = ( int ) $catnum;
		}
		if (count ( $catlist ))
			$latestcats = " AND m.catid IN (" . implode ( ',', $catlist ) . ") ";

		$this->document->setTitle ( _KUNENA_ALL_DISCUSSIONS . ' - ' . stripslashes ( $this->config->board_title ) );
		$query = "Select COUNT(DISTINCT t.thread) FROM #__fb_messages AS t
			INNER JOIN #__fb_messages AS m ON m.id=t.thread
		WHERE m.moved='0' AND m.hold='0' AND m.catid IN ({$this->session->allowed})
		AND t.time >'{$this->querytime}' AND t.hold=0 AND t.moved=0 AND t.catid IN ({$this->session->allowed})" . $latestcats; // if categories are limited apply filter


		$this->db->setQuery ( $query );
		$this->total = ( int ) $this->db->loadResult ();
		check_dberror ( 'Unable to count total threads' );
		$offset = ($this->page - 1) * $this->threads_per_page;

		$this->order = "lastid DESC";
		$query = "SELECT m.id, MAX(t.id) AS lastid FROM #__fb_messages AS t
			INNER JOIN #__fb_messages AS m ON m.id=t.thread
			WHERE m.moved='0' AND m.hold='0' AND m.catid IN ({$this->session->allowed})
			AND t.time>'{$this->querytime}' AND t.hold='0' AND t.moved='0' AND t.catid IN ({$this->session->allowed}) {$latestcats}
			GROUP BY t.thread
			ORDER BY {$this->order}
		";

		$this->db->setQuery ( $query, $offset, $this->threads_per_page );
		$this->threadids = $this->db->loadResultArray ();
		check_dberror ( "Unable to load thread list." );
		$this->_common();
	}

	function getNoReplies() {
		if (isset($this->total)) return;
		$this->document->setTitle ( _KUNENA_NO_REPLIES . ' - ' . stripslashes ( $this->config->board_title ) );
		$query = "SELECT COUNT(DISTINCT tmp.thread) FROM
			(SELECT m.thread, count(*) AS posts
				FROM #__fb_messages as m
				JOIN (SELECT thread
					FROM #__fb_messages
					WHERE time >'$this->querytime' AND parent=0 AND
						hold=0 AND moved=0 AND catid IN ({$this->session->allowed})
				) as t ON m.thread = t.thread
				WHERE m.hold=0 AND m.moved=0
				GROUP BY 1
			) AS tmp
			WHERE tmp.posts = 1";

		$this->db->setQuery ( $query );
		$this->total = ( int ) $this->db->loadResult ();
		check_dberror ( 'Unable to count total threads' );
		$offset = ($this->page - 1) * $this->threads_per_page;

		$this->order = "lastid DESC";
		$query = "SELECT thread, thread as lastid FROM
			(SELECT m.thread, count(*) AS posts
				FROM #__fb_messages as m
				JOIN (SELECT thread
					FROM #__fb_messages
					WHERE time >'{$this->querytime}' AND parent=0 AND
						hold=0 AND moved=0 AND catid IN ({$this->session->allowed})
				) as t ON m.thread = t.thread
				WHERE m.hold=0 AND m.moved=0
				GROUP BY 1
			) AS tmp
			WHERE tmp.posts = 1
			GROUP BY thread
			ORDER BY {$this->order}";

		$this->db->setQuery ( $query, $offset, $this->threads_per_page );
		$this->threadids = $this->db->loadResultArray ();
		check_dberror ( "Unable to load thread list." );
		$this->_common();
	}

	function displayPathway() {
		if (file_exists ( KUNENA_ABSTMPLTPATH . DS . 'pathway.php' )) {
			require_once (KUNENA_ABSTMPLTPATH . DS . 'pathway.php');
		} else {
			require_once (KUNENA_PATH_TEMPLATE_DEFAULT . DS . 'pathway.php');
		}
	}

	function displayAnnouncement() {
		if ($this->config->showannouncement > 0) {
			if (file_exists ( KUNENA_ABSTMPLTPATH . DS . 'plugin' . DS . 'announcement' . DS . 'announcementbox.php' )) {
				require_once (KUNENA_ABSTMPLTPATH . DS . 'plugin' . DS . 'announcement' . DS . 'announcementbox.php');
			} else {
				require_once (KUNENA_PATH_TEMPLATE_DEFAULT . DS . 'plugin' . DS . 'announcement' . DS . 'announcementbox.php');
			}
		}
	}

	function displayForumJump() {
		if ($this->config->enableforumjump)
			require_once (KUNENA_PATH_LIB . DS . 'kunena.forumjump.php');
	}

	function displayFlat() {
		if (! $this->allow) {
			echo _KUNENA_NO_ACCESS;
			return;
		}
		if (file_exists ( KUNENA_ABSTMPLTPATH . DS . 'threads' . DS . 'flat.php' )) {
			include (KUNENA_ABSTMPLTPATH . DS . 'threads' . DS . 'flat.php');
		} else {
			include (KUNENA_PATH_TEMPLATE_DEFAULT . DS . 'threads' . DS . 'flat.php');
		}
	}

	function displayStats() {
		if ($this->config->showstats > 0) {
			if (file_exists ( KUNENA_ABSTMPLTPATH . DS . 'plugin' . DS . 'stats' . DS . 'stats.class.php' )) {
				include_once (KUNENA_ABSTMPLTPATH . DS . 'plugin' . DS . 'stats' . DS . 'stats.class.php');
			} else {
				include_once (KUNENA_PATH_TEMPLATE_DEFAULT . DS . 'plugin' . DS . 'stats' . DS . 'stats.class.php');
			}

			$kunena_stats = new CKunenaStats ( );
			$kunena_stats->showFrontStats ();
		}
	}

	function displayWhoIsOnline() {
		if ($this->config->showwhoisonline > 0) {
			if (file_exists ( KUNENA_ABSTMPLTPATH . DS . 'plugin' . DS . 'who' . DS . 'whoisonline.php' )) {
				include (KUNENA_ABSTMPLTPATH . DS . 'plugin' . DS . 'who' . DS . 'whoisonline.php');
			} else {
				include (KUNENA_PATH_TEMPLATE_DEFAULT . DS . 'plugin' . DS . 'who' . DS . 'whoisonline.php');
			}
		}
	}

	function getPagination($func, $sel, $page, $totalpages, $maxpages) {
		$startpage = ($page - floor ( $maxpages / 2 ) < 1) ? 1 : $page - floor ( $maxpages / 2 );
		$endpage = $startpage + $maxpages;
		if ($endpage > $totalpages) {
			$startpage = ($totalpages - $maxpages) < 1 ? 1 : $totalpages - $maxpages;
			$endpage = $totalpages;
		}

		$output = '<span class="kpagination">' . _PAGE;

		if (($startpage) > 1) {
			if ($endpage < $totalpages)
				$endpage --;
			$output .= CKunenaLink::GetLatestPageLink ( $func, 1, 'follow', '', $sel );
			if (($startpage) > 2) {
				$output .= "...";
			}
		}

		for($i = $startpage; $i <= $endpage && $i <= $totalpages; $i ++) {
			if ($page == $i) {
				$output .= "<strong>$i</strong>";
			} else {
				$output .= CKunenaLink::GetLatestPageLink ( $func, $i, 'follow', '', $sel );
			}
		}

		if ($endpage < $totalpages) {
			if ($endpage < $totalpages - 1) {
				$output .= "...";
			}

			$output .= CKunenaLink::GetLatestPageLink ( $func, $totalpages, 'follow', '', $sel );
		}

		$output .= '</span>';
		return $output;
	}

	function display() {
		if (! $this->allow) {
			echo _KUNENA_NO_ACCESS;
			return;
		}
		if ($this->func == 'mylatest') $this->getMyLatest();
		else if ($this->func == 'noreplies') $this->getNoReplies();
		else $this->getLatest();

		if (file_exists ( KUNENA_ABSTMPLTPATH . DS . 'threads' . DS . 'latestx.php' )) {
			include (KUNENA_ABSTMPLTPATH . DS . 'threads' . DS . 'latestx.php');
		} else {
			include (KUNENA_PATH_TEMPLATE_DEFAULT . DS . 'threads' . DS . 'latestx.php');
		}
	}
}
