<?php
/*
 * Copyright (C) 2005-2011 Alfresco Software Limited.
 *
 * This file is part of Alfresco
 *
 * Alfresco is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Alfresco is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with Alfresco. If not, see <http://www.gnu.org/licenses/>.
 */

require_once("AlfrescoConfig.php");

if (isset($_SERVER["ALF_AVAILABLE"]) == false) {
	require_once("Alfresco/Service/Session.php");
	require_once("Alfresco/Service/SpacesStore.php");
	require_once("Alfresco/Service/Node.php");
	require_once("Alfresco/Service/Version.php");
}

// TODO .. for now remove this as it it not available when running within Quercus
//require_once("Alfresco/Service/Logger/Logger.php");

// Register the various event hooks
$wgHooks['ArticleSave'][] = 'alfArticleSave';
$wgHooks['TitleMoveComplete'][] = 'alfTitleMoveComplete';

/**
 * Hook function called before content is saved.  At this point we can extract information about the article
 * and store it on the session to be used later.
 *
 * @param WikiPage $article
 * @param User $user
 * @param string $text
 * @param string $summary
 * @param bool $minor
 * @param NULL $watch
 * @param NULL $sectionAnchor
 * @param int $flags
 * @return bool
 */
function alfArticleSave(WikiPage $article, User $user, &$text, &$summary, $minor, $watch, $sectionAnchor, &$flags) {
	// Execute a query to get the previous versions URL, we can use this later when we save the content
	// and want to update the version history.
	$url = null;
	$fieldName = 'old_text';
	$revision = Revision::newFromId($article->mLatest);
	if (isset($revision)) {
		$dbr = wfGetDB(DB_SLAVE);
		$row = $dbr->selectRow(
			'text',
			array('old_text', 'old_flags'),
			array('old_id' => $revision->getTextId()),
			'ExternalStoreAlfresco::alfArticleSave'
		);
		$url = $row->$fieldName;
	}

	// Store the details of the article in the session
	$_SESSION["title"] = ExternalStoreAlfresco::getTitle($article->getTitle());
	$_SESSION["description"] = $summary;
	$_SESSION["lastVersionUrl"] = $url;

	// Returning true ensures that the document is saved
	return TRUE;
}

function alfTitleMoveComplete(&$title, &$newtitle, &$user, $pageid, $redirid) {
	//$logger = new Logger("integration.mediawiki.ExternalStoreAlfresco");

	//if ($logger->isDebugEnabled() == true)
	//{
	//	$logger->debug("Handling title move event");
	//	$logger->debug(	  "title=".ExternalStoreAlfresco::getTitle($title).
	//				    "; newTitle=".ExternalStoreAlfresco::getTitle($newtitle).
	//					"; user=".$user->getName().
	//					"; pageid=".$pageid.		// is page_id on page table
	//					"; redirid=".$redirid);
	//}

	// Do summert :D
}

/**
 * External Alfresco content store.
 *
 * This store retrieves and stores content from MediWiki into a space in a given Alfresco repository.
 */
class ExternalStoreAlfresco {
	//private $logger;
	private $session;

	/**
	 * @var Store
	 */
	private $store;
	private $wikiSpace;

	/**
	 * Constructor
	 */
	public function __construct() {
		//$this->logger = new Logger("integration.mediawiki.ExternalStoreAlfresco");

		// Create the session
		$repository = new Repository($GLOBALS['alfURL']);
		try {
			$ticket = $repository->authenticate($GLOBALS['alfUser'], $GLOBALS['alfPassword']);
		} catch (Exception $e) {
			die('Could not authenticate user "' . $GLOBALS['alfUser'] . '"');
		}

		$this->session = $repository->createSession($ticket);

		// Get the store
		$this->store = $this->session->getStoreFromString($GLOBALS['alfWikiStore']);

		// Get the wiki space
		$results = $this->session->query($this->store, 'PATH:"' . $GLOBALS['alfWikiSpace'] . '"');
		$this->wikiSpace = $results[0];
	}

	/**
	 * Fetches the content from the Alfresco repository.
	 *
	 * @param string $url the URL to the alfresco content
	 * @return string
	 */
	public function fetchFromURL($url) {
		$version = $this->urlToVersion($url);
		return $version->cm_content->content;
	}

	/**
	 * Stores the provided content in the Alfresco repository
	 *
	 * @param mixed $store the external store
	 * @param string $data the content
	 * @return string
	 */
	public function &store($store, $data) {
		if (isset($GLOBALS['alfImpersonateUsers']) && strtolower($GLOBALS['alfUser']) !== strtolower($_SESSION['wsUserName'])) {
			$this->impersonate(strtolower($_SESSION['wsUserName']));
		}

		$url = $_SESSION["lastVersionUrl"];
		/** @var $node Node */
		$node = null;

		$isNormalText = (strpos($url, 'alfresco://') === false);

		if ($url != null && $isNormalText == false) {
			$node = $this->urlToNode($url);
		}
		else {
			$node = $this->wikiSpace->createChild("cm_content", "cm_contains", "cm_" . $_SESSION["title"]);
			$node->cm_name = $_SESSION["title"];

			$node->addAspect("cm_versionable", null);
			$node->cm_initialVersion = false;
			$node->cm_autoVersion = false;
		}

		// Set the content and save
		$node->updateContent("cm_content", "text/plain", "UTF-8", $data);
		$this->session->save();

		$description = $_SESSION["description"];
		if ($description == null) {
			$description = '';
		}

		// Create the version
		$version = $node->createVersion($description);

		$result = 'alfresco://' . $node->store->scheme . '/' . $node->store->address . '/' . $node->id . '/' . $version->store->scheme . '/' . $version->store->address . '/' . $version->id;
		return $result;
	}

	/**
	 * Impersonates default Alfresco user with current MediaWiki user.
	 *
	 * @param string $userName
	 * @return void
	 */
	private function impersonate($userName) {
		if (isset($GLOBALS['alfImpersonateUsers'][$userName])) {
			$impersonateUserName = $GLOBALS['alfImpersonateUsers'][$userName]['user'];
			$impersonatePassword = $GLOBALS['alfImpersonateUsers'][$userName]['password'];

			// Recreate the session
			$repository = new Repository($GLOBALS['alfURL']);
			$ticket = $repository->authenticate($impersonateUserName, $impersonatePassword);
			$this->session = $repository->createSession($ticket);
		}
	}

	/**
	 * Converts the url to the the node it relates to
	 *
	 * @param string $url
	 * @return
	 */
	private function urlToNode($url) {
		$values = explode('/', substr($url, 11));
		return $this->session->getNode($this->store, $values[2]);
	}

	/**
	 * Convert the url to the version it relates to
	 *
	 * @param string $url
	 * @return Version
	 */
	private function urlToVersion($url) {
		$values = explode('/', substr($url, 11));
		$store = $this->session->getStore($values[4], $values[3]);
		return new Version($this->session, $store, $values[5]);
	}

	/**
	 * Gets the title.
	 *
	 * @param Title $titleObject
	 * @return string
	 */
	public static function getTitle(Title $titleObject) {
		// Sort out the namespace of this article so we can figure out what the title is
		$title = $titleObject->getText();
		$ns = $titleObject->getNamespace();
		if ($ns != NS_MAIN) {
			// lookup the display name of the namespace
			$title = MWNamespace::getCanonicalName($ns) . " - " . $title;
		}
		return $title;
	}
}

?>