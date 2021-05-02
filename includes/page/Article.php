<?php
/**
 * User interface for page actions.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\HookContainer\ProtectedHookAccessorTrait;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNameUtils;
use MediaWiki\Watchlist\WatchlistManager;
use Wikimedia\IPUtils;
use Wikimedia\NonSerializable\NonSerializableTrait;

/**
 * Class for viewing MediaWiki article and history.
 *
 * This maintains WikiPage functions for backwards compatibility.
 *
 * @todo Move and rewrite code to an Action class
 *
 * Note: edit user interface and cache support functions have been
 * moved to separate EditPage and HTMLFileCache classes.
 */
class Article implements Page {
	use ProtectedHookAccessorTrait;
	use NonSerializableTrait;

	/**
	 * @var IContextSource|null The context this Article is executed in.
	 * If null, RequestContext::getMain() is used.
	 * @deprecated since 1.35, must be private, use {@link getContext}
	 */
	protected $mContext;

	/** @var WikiPage The WikiPage object of this instance */
	protected $mPage;

	/**
	 * @var int|null The oldid of the article that was requested to be shown,
	 * 0 for the current revision.
	 */
	public $mOldId;

	/** @var Title|null Title from which we were redirected here, if any. */
	public $mRedirectedFrom = null;

	/** @var string|bool URL to redirect to or false if none */
	public $mRedirectUrl = false;

	/**
	 * @var Status|null represents the outcome of fetchRevisionRecord().
	 * $fetchResult->value is the RevisionRecord object, if the operation was successful.
	 */
	private $fetchResult = null;

	/**
	 * @var ParserOutput|null|false The ParserOutput generated for viewing the page,
	 * initialized by view(). If no ParserOutput could be generated, this is set to false.
	 * @deprecated since 1.32
	 */
	public $mParserOutput = null;

	/**
	 * @var bool Whether render() was called. With the way subclasses work
	 * here, there doesn't seem to be any other way to stop calling
	 * OutputPage::enableSectionEditLinks() and still have it work as it did before.
	 */
	protected $viewIsRenderAction = false;

	/**
	 * @var LinkRenderer
	 */
	protected $linkRenderer;

	/**
	 * @var RevisionStore
	 */
	private $revisionStore;

	/**
	 * @var WatchlistManager
	 */
	private $watchlistManager;

	/**
	 * @var UserNameUtils
	 */
	private $userNameUtils;

	/**
	 * @var RevisionRecord|null Revision to be shown
	 *
	 * Initialized by getOldIDFromRequest() or fetchRevisionRecord(). While the output of
	 * Article::view is typically based on this revision, it may be replaced by extensions.
	 */
	private $mRevisionRecord = null;

	/**
	 * @param Title $title
	 * @param int|null $oldId Revision ID, null to fetch from request, zero for current
	 */
	public function __construct( Title $title, $oldId = null ) {
		$this->mOldId = $oldId;
		$this->mPage = $this->newPage( $title );

		$services = MediaWikiServices::getInstance();
		$this->linkRenderer = $services->getLinkRenderer();
		$this->revisionStore = $services->getRevisionStore();
		$this->watchlistManager = $services->getWatchlistManager();
		$this->userNameUtils = $services->getUserNameUtils();
	}

	/**
	 * @param Title $title
	 * @return WikiPage
	 */
	protected function newPage( Title $title ) {
		return new WikiPage( $title );
	}

	/**
	 * Constructor from a page id
	 * @param int $id Article ID to load
	 * @return Article|null
	 */
	public static function newFromID( $id ) {
		$t = Title::newFromID( $id );
		return $t == null ? null : new static( $t );
	}

	/**
	 * Create an Article object of the appropriate class for the given page.
	 *
	 * @param Title $title
	 * @param IContextSource $context
	 * @return Article
	 */
	public static function newFromTitle( $title, IContextSource $context ) {
		if ( $title->getNamespace() === NS_MEDIA ) {
			// XXX: This should not be here, but where should it go?
			$title = Title::makeTitle( NS_FILE, $title->getDBkey() );
		}

		$page = null;
		Hooks::runner()->onArticleFromTitle( $title, $page, $context );
		if ( !$page ) {
			switch ( $title->getNamespace() ) {
				case NS_FILE:
					$page = new ImagePage( $title );
					break;
				case NS_CATEGORY:
					$page = new CategoryPage( $title );
					break;
				default:
					$page = new Article( $title );
			}
		}
		$page->setContext( $context );

		return $page;
	}

	/**
	 * Create an Article object of the appropriate class for the given page.
	 *
	 * @param WikiPage $page
	 * @param IContextSource $context
	 * @return Article
	 */
	public static function newFromWikiPage( WikiPage $page, IContextSource $context ) {
		$article = self::newFromTitle( $page->getTitle(), $context );
		$article->mPage = $page; // override to keep process cached vars
		return $article;
	}

	/**
	 * Get the page this view was redirected from
	 * @return Title|null
	 * @since 1.28
	 */
	public function getRedirectedFrom() {
		return $this->mRedirectedFrom;
	}

	/**
	 * Tell the page view functions that this view was redirected
	 * from another page on the wiki.
	 * @param Title $from
	 */
	public function setRedirectedFrom( Title $from ) {
		$this->mRedirectedFrom = $from;
	}

	/**
	 * Get the title object of the article
	 *
	 * @return Title Title object of this page
	 */
	public function getTitle() {
		return $this->mPage->getTitle();
	}

	/**
	 * Get the WikiPage object of this instance
	 *
	 * @since 1.19
	 * @return WikiPage
	 */
	public function getPage() {
		return $this->mPage;
	}

	public function clear() {
		$this->mRedirectedFrom = null; # Title object if set
		$this->mRedirectUrl = false;
		$this->mRevisionRecord = null;
		$this->fetchResult = null;

		// TODO hard-deprecate direct access to public fields

		$this->mPage->clear();
	}

	/**
	 * Returns a Content object representing the pages effective display content,
	 * not necessarily the revision's content!
	 *
	 * Note that getContent does not follow redirects anymore.
	 * If you need to fetch redirectable content easily, try
	 * the shortcut in WikiPage::getRedirectTarget()
	 *
	 * This function has side effects! Do not use this function if you
	 * only want the real revision text if any.
	 *
	 * @deprecated since 1.32, use fetchRevisionRecord() instead. Hard since 1.36.
	 *
	 * @return Content|null
	 *
	 * @since 1.21
	 */
	protected function getContentObject() {
		wfDeprecated( __METHOD__, '1.32' );
		$content = null;
		if ( $this->mPage->getId() === 0 ) {
			$content = $this->getSubstituteContent();
		} else {
			$revision = $this->fetchRevisionRecord();
			if ( $revision ) {
				$content = $revision->getContent(
					SlotRecord::MAIN,
					RevisionRecord::FOR_THIS_USER,
					$this->getContext()->getUser()
				);
			}
		}
		return $content;
	}

	/**
	 * Returns Content object to use when the page does not exist.
	 *
	 * @return Content
	 */
	private function getSubstituteContent() {
		# If this is a MediaWiki:x message, then load the messages
		# and return the message value for x.
		if ( $this->getTitle()->getNamespace() === NS_MEDIAWIKI ) {
			$text = $this->getTitle()->getDefaultMessageText();
			if ( $text === false ) {
				$text = '';
			}

			$content = ContentHandler::makeContent( $text, $this->getTitle() );
		} else {
			$message = $this->getContext()->getUser()->isRegistered() ? 'noarticletext' : 'noarticletextanon';
			$content = new MessageContent( $message, null );
		}

		return $content;
	}

	/**
	 * @see getOldIDFromRequest()
	 * @see getRevIdFetched()
	 *
	 * @return int The oldid of the article that is was requested in the constructor or via the
	 *         context's WebRequest.
	 */
	public function getOldID() {
		if ( $this->mOldId === null ) {
			$this->mOldId = $this->getOldIDFromRequest();
		}

		return $this->mOldId;
	}

	/**
	 * Sets $this->mRedirectUrl to a correct URL if the query parameters are incorrect
	 *
	 * @return int The old id for the request
	 */
	public function getOldIDFromRequest() {
		$this->mRedirectUrl = false;

		$request = $this->getContext()->getRequest();
		$oldid = $request->getIntOrNull( 'oldid' );

		if ( $oldid === null ) {
			return 0;
		}

		if ( $oldid !== 0 ) {
			# Load the given revision and check whether the page is another one.
			# In that case, update this instance to reflect the change.
			if ( $oldid === $this->mPage->getLatest() ) {
				$this->mRevisionRecord = $this->mPage->getRevisionRecord();
			} else {
				$this->mRevisionRecord = $this->revisionStore->getRevisionById( $oldid );
				if ( $this->mRevisionRecord !== null ) {
					$revPageId = $this->mRevisionRecord->getPageId();
					// Revision title doesn't match the page title given?
					if ( $this->mPage->getId() != $revPageId ) {
						$function = get_class( $this->mPage ) . '::newFromID';
						$this->mPage = $function( $revPageId );
					}
				}
			}
		}

		$oldRev = $this->mRevisionRecord;
		if ( $request->getVal( 'direction' ) == 'next' ) {
			$nextid = 0;
			if ( $oldRev ) {
				$nextRev = $this->revisionStore->getNextRevision( $oldRev );
				if ( $nextRev ) {
					$nextid = $nextRev->getId();
				}
			}
			if ( $nextid ) {
				$oldid = $nextid;
				$this->mRevisionRecord = null;
			} else {
				$this->mRedirectUrl = $this->getTitle()->getFullURL( 'redirect=no' );
			}
		} elseif ( $request->getVal( 'direction' ) == 'prev' ) {
			$previd = 0;
			if ( $oldRev ) {
				$prevRev = $this->revisionStore->getPreviousRevision( $oldRev );
				if ( $prevRev ) {
					$previd = $prevRev->getId();
				}
			}
			if ( $previd ) {
				$oldid = $previd;
				$this->mRevisionRecord = null;
			}
		}

		return $oldid;
	}

	/**
	 * Fetches the revision to work on.
	 * The revision is loaded from the database. Refer to $this->fetchResult for the revision
	 * or any errors encountered while loading it.
	 *
	 * Public since 1.35
	 *
	 * @return RevisionRecord|null
	 */
	public function fetchRevisionRecord() {
		if ( $this->fetchResult ) {
			return $this->mRevisionRecord;
		}

		$oldid = $this->getOldID();

		// $this->mRevisionRecord might already be fetched by getOldIDFromRequest()
		if ( !$this->mRevisionRecord ) {
			if ( !$oldid ) {
				$this->mRevisionRecord = $this->mPage->getRevisionRecord();

				if ( !$this->mRevisionRecord ) {
					wfDebug( __METHOD__ . " failed to find page data for title " .
						$this->getTitle()->getPrefixedText() );

					// Just for sanity, output for this case is done by showMissingArticle().
					$this->fetchResult = Status::newFatal( 'noarticletext' );
					return null;
				}
			} else {
				$this->mRevisionRecord = $this->revisionStore->getRevisionById( $oldid );

				if ( !$this->mRevisionRecord ) {
					wfDebug( __METHOD__ . " failed to load revision, rev_id $oldid" );

					$this->fetchResult = Status::newFatal( 'missing-revision', $oldid );
					return null;
				}
			}
		}

		if ( !$this->mRevisionRecord->userCan( RevisionRecord::DELETED_TEXT, $this->getContext()->getAuthority() ) ) {
			wfDebug( __METHOD__ . " failed to retrieve content of revision " .
				$this->mRevisionRecord->getId() );

			// Just for sanity, output for this case is done by showDeletedRevisionHeader().
			// title used in wikilinks, should not contain whitespaces
			$this->fetchResult = Status::newFatal(
				'rev-deleted-text-permission', $this->getTitle()->getPrefixedDBkey() );
			return null;
		}

		$this->fetchResult = Status::newGood( $this->mRevisionRecord );
		return $this->mRevisionRecord;
	}

	/**
	 * Returns true if the currently-referenced revision is the current edit
	 * to this page (and it exists).
	 * @return bool
	 */
	public function isCurrent() {
		# If no oldid, this is the current version.
		if ( $this->getOldID() == 0 ) {
			return true;
		}

		return $this->mPage->exists() &&
			$this->mRevisionRecord &&
			$this->mRevisionRecord->isCurrent();
	}

	/**
	 * Use this to fetch the rev ID used on page views
	 *
	 * Before fetchRevisionRecord was called, this returns the page's latest revision,
	 * regardless of what getOldID() returns.
	 *
	 * @return int Revision ID of last article revision
	 */
	public function getRevIdFetched() {
		if ( $this->fetchResult && $this->fetchResult->isOK() ) {
			return $this->fetchResult->value->getId();
		} else {
			return $this->mPage->getLatest();
		}
	}

	/**
	 * This is the default action of the index.php entry point: just view the
	 * page of the given title.
	 */
	public function view() {
		global $wgUseFileCache;

		# Get variables from query string
		# As side effect this will load the revision and update the title
		# in a revision ID is passed in the request, so this should remain
		# the first call of this method even if $oldid is used way below.
		$oldid = $this->getOldID();

		$user = $this->getContext()->getUser();
		# Another check in case getOldID() is altering the title
		$permissionStatus = PermissionStatus::newEmpty();
		if ( !$this->getContext()->getAuthority()
			->authorizeRead( 'read', $this->getTitle(), $permissionStatus )
		) {
			wfDebug( __METHOD__ . ": denied on secondary read check" );
			throw new PermissionsError( 'read', $permissionStatus );
		}

		$outputPage = $this->getContext()->getOutput();
		# getOldID() may as well want us to redirect somewhere else
		if ( $this->mRedirectUrl ) {
			$outputPage->redirect( $this->mRedirectUrl );
			wfDebug( __METHOD__ . ": redirecting due to oldid" );

			return;
		}

		# If we got diff in the query, we want to see a diff page instead of the article.
		if ( $this->getContext()->getRequest()->getCheck( 'diff' ) ) {
			wfDebug( __METHOD__ . ": showing diff page" );
			$this->showDiffPage();

			return;
		}

		# Set page title (may be overridden by DISPLAYTITLE)
		$outputPage->setPageTitle( $this->getTitle()->getPrefixedText() );

		$outputPage->setArticleFlag( true );
		# Allow frames by default
		$outputPage->allowClickjacking();

		$parserOptions = $this->getParserOptions();
		$poOptions = [];
		# Allow extensions to vary parser options used for article rendering
		Hooks::runner()->onArticleParserOptions( $this, $parserOptions );
		# Render printable version, use printable version cache
		if ( $outputPage->isPrintable() ) {
			$parserOptions->setIsPrintable( true );
			$poOptions['enableSectionEditLinks'] = false;
			$outputPage->prependHTML(
				Html::warningBox(
					$outputPage->msg( 'printableversion-deprecated-warning' )->escaped()
				)
			);
		} elseif ( $this->viewIsRenderAction || !$this->isCurrent() ||
			!$this->getContext()->getAuthority()->probablyCan( 'edit', $this->getTitle() )
		) {
			$poOptions['enableSectionEditLinks'] = false;
		}

		# Try client and file cache
		if ( $oldid === 0 && $this->mPage->checkTouched() ) {
			# Try to stream the output from file cache
			if ( $wgUseFileCache && $this->tryFileCache() ) {
				wfDebug( __METHOD__ . ": done file cache" );
				# tell wgOut that output is taken care of
				$outputPage->disable();
				$this->mPage->doViewUpdates( $user, $oldid );

				return;
			}
		}

		$this->showRedirectedFromHeader();
		$this->showNamespaceHeader();

		$continue =
			$this->generateContentOutput( $user, $parserOptions, $oldid, $outputPage, $poOptions );

		if ( !$continue ) {
			return;
		}

		# For the main page, overwrite the <title> element with the con-
		# tents of 'pagetitle-view-mainpage' instead of the default (if
		# that's not empty).
		# This message always exists because it is in the i18n files
		if ( $this->getTitle()->isMainPage() ) {
			$msg = wfMessage( 'pagetitle-view-mainpage' )->inContentLanguage();
			if ( !$msg->isDisabled() ) {
				$outputPage->setHTMLTitle( $msg->page( $this->getTitle() )->text() );
			}
		}

		# Use adaptive TTLs for CDN so delayed/failed purges are noticed less often.
		# This could use getTouched(), but that could be scary for major template edits.
		$outputPage->adaptCdnTTL( $this->mPage->getTimestamp(), IExpiringStore::TTL_DAY );

		$this->showViewFooter();
		$this->mPage->doViewUpdates( $user, $oldid ); // FIXME: test this

		# Load the postEdit module if the user just saved this revision
		# See also EditPage::setPostEditCookie
		$request = $this->getContext()->getRequest();
		$cookieKey = EditPage::POST_EDIT_COOKIE_KEY_PREFIX . $this->getRevIdFetched();
		$postEdit = $request->getCookie( $cookieKey );
		if ( $postEdit ) {
			# Clear the cookie. This also prevents caching of the response.
			$request->response()->clearCookie( $cookieKey );
			$outputPage->addJsConfigVars( 'wgPostEdit', $postEdit );
			$outputPage->addModules( 'mediawiki.action.view.postEdit' ); // FIXME: test this
		}
	}

	/**
	 * Determines the desired ParserOutput and passes it to $outputPage.
	 *
	 * @param Authority $performer
	 * @param ParserOptions $parserOptions
	 * @param int $oldid
	 * @param OutputPage $outputPage
	 * @param array $textOptions
	 *
	 * @return bool True if further processing like footer generation should be applied,
	 *              false to skip further processing.
	 */
	private function generateContentOutput(
		Authority $performer,
		ParserOptions $parserOptions,
		int $oldid,
		OutputPage $outputPage,
		array $textOptions
	): bool {
		# Should the parser cache be used?
		$useParserCache = true;
		$pOutput = null;
		$parserOutputAccess = MediaWikiServices::getInstance()->getParserOutputAccess();

		// NOTE: $outputDone and $useParserCache may be changed by the hook
		$this->getHookRunner()->onArticleViewHeader( $this, $outputDone, $useParserCache );
		if ( $outputDone ) {
			if ( $outputDone instanceof ParserOutput ) {
				$pOutput = $outputDone;
			}

			if ( $pOutput ) {
				$this->doOutputMetaData( $pOutput, $outputPage );
			}
			return true;
		}

		// Early abort if the page doesn't exist
		if ( !$this->mPage->exists() ) {
			wfDebug( __METHOD__ . ": showing missing article" );
			$this->showMissingArticle();
			$this->mPage->doViewUpdates( $performer );
			return false; // skip all further output to OutputPage
		}

		// Try the latest parser cache
		// NOTE: try latest-revision cache first to avoid loading revision.
		if ( $useParserCache && !$oldid ) {
			$pOutput = $parserOutputAccess->getCachedParserOutput(
				$this->getPage(),
				$parserOptions,
				null,
				ParserOutputAccess::OPT_NO_AUDIENCE_CHECK // we already checked
			);

			if ( $pOutput ) {
				$this->doOutputFromParserCache( $pOutput, $outputPage, $textOptions );
				$this->doOutputMetaData( $pOutput, $outputPage );
				return true;
			}
		}

		$rev = $this->fetchRevisionRecord();
		if ( !$this->fetchResult->isOK() ) {
			$this->showViewError( $this->fetchResult->getWikiText(
				false, false, $this->getContext()->getLanguage()
			) );
			return true;
		}

		# Are we looking at an old revision
		if ( $oldid ) {
			$this->setOldSubtitle( $oldid );

			if ( !$this->showDeletedRevisionHeader() ) {
				wfDebug( __METHOD__ . ": cannot view deleted revision" );
				return false; // skip all further output to OutputPage
			}

			// Try the old revision parser cache
			// NOTE: Repeating cache check for old revision to avoid fetching $rev
			// before it's absolutely necessary.
			if ( $useParserCache ) {
				$pOutput = $parserOutputAccess->getCachedParserOutput(
					$this->getPage(),
					$parserOptions,
					$rev,
					ParserOutputAccess::OPT_NO_AUDIENCE_CHECK // we already checked in fetchRevisionRevord
				);

				if ( $pOutput ) {
					$this->doOutputFromParserCache( $pOutput, $outputPage, $textOptions );
					$this->doOutputMetaData( $pOutput, $outputPage );
					return true;
				}
			}
		}

		# Ensure that UI elements requiring revision ID have
		# the correct version information.
		$outputPage->setRevisionId( $this->getRevIdFetched() );
		# Preload timestamp to avoid a DB hit
		$outputPage->setRevisionTimestamp( $rev->getTimestamp() );

		# Pages containing custom CSS or JavaScript get special treatment
		if ( $this->getTitle()->isSiteConfigPage() || $this->getTitle()->isUserConfigPage() ) {
			$dir = $this->getContext()->getLanguage()->getDir();
			$lang = $this->getContext()->getLanguage()->getHtmlCode();

			$outputPage->wrapWikiMsg(
				"<div id='mw-clearyourcache' lang='$lang' dir='$dir' class='mw-content-$dir'>\n$1\n</div>",
				'clearyourcache'
			);
			$outputPage->addModuleStyles( 'mediawiki.action.styles' );
		} elseif ( !$this->getHookRunner()->onArticleRevisionViewCustom(
			$rev,
			$this->getTitle(),
			$oldid,
			$outputPage )
		) {
			// NOTE: sync with hooks called in DifferenceEngine::renderNewRevision()
			// Allow extensions do their own custom view for certain pages
			$this->doOutputMetaData( $pOutput, $outputPage );
			return true;
		}

		# Run the parse, protected by a pool counter
		wfDebug( __METHOD__ . ": doing uncached parse" );

		if ( !$rev ) {
			// No revision, abort! Shouldn't happen.
			return false;
		}

		$opt = 0;

		// we already checked the cache in case 2, don't check again.
		$opt |= ParserOutputAccess::OPT_NO_CHECK_CACHE;

		// we already checked in fetchRevisionRecord()
		$opt |= ParserOutputAccess::OPT_NO_AUDIENCE_CHECK;

		if ( !$rev->getId() || !$useParserCache ) {
			// fake revision or uncacheable options
			$opt |= ParserOutputAccess::OPT_NO_CACHE;
		}

		$renderStatus = $parserOutputAccess->getParserOutput(
			$this->getPage(),
			$parserOptions,
			$rev,
			$opt
		);

		$this->doOutputFromRenderStatus(
			$rev,
			$renderStatus,
			$outputPage,
			$textOptions
		);

		if ( !$renderStatus->isOK() ) {
			return true;
		}

		$pOutput = $renderStatus->getValue();
		$this->doOutputMetaData( $pOutput, $outputPage );
		return true;
	}

	/**
	 * @param ?ParserOutput $pOutput
	 * @param OutputPage $outputPage
	 */
	private function doOutputMetaData( ?ParserOutput $pOutput, OutputPage $outputPage ) {
		# Adjust title for main page & pages with displaytitle
		if ( $pOutput ) {
			$this->adjustDisplayTitle( $pOutput );
		}

		# Check for any __NOINDEX__ tags on the page using $pOutput
		$policy = $this->getRobotPolicy( 'view', $pOutput ?: null );
		$outputPage->setIndexPolicy( $policy['index'] );
		$outputPage->setFollowPolicy( $policy['follow'] ); // FIXME: test this

		$this->mParserOutput = $pOutput;
	}

	/**
	 * @param ParserOutput $pOutput
	 * @param OutputPage $outputPage
	 * @param array $textOptions
	 */
	private function doOutputFromParserCache(
		ParserOutput $pOutput,
		OutputPage $outputPage,
		array $textOptions
	) {
		# Ensure that UI elements requiring revision ID have
		# the correct version information.
		$outputPage->setRevisionId( $pOutput->getCacheRevisionId() ?? $this->getRevIdFetched() );

		$outputPage->addParserOutput( $pOutput, $textOptions );
		# Preload timestamp to avoid a DB hit
		$cachedTimestamp = $pOutput->getTimestamp();
		if ( $cachedTimestamp !== null ) {
			$outputPage->setRevisionTimestamp( $cachedTimestamp );
			$this->mPage->setTimestamp( $cachedTimestamp );
		}
	}

	/**
	 * @param RevisionRecord|null $rev
	 * @param Status $renderStatus
	 * @param OutputPage $outputPage
	 * @param array $textOptions
	 */
	private function doOutputFromRenderStatus(
		?RevisionRecord $rev,
		Status $renderStatus,
		OutputPage $outputPage,
		array $textOptions
	) {
		global $wgCdnMaxageStale;
		$ok = $renderStatus->isOK();

		$pOutput = $ok ? $renderStatus->getValue() : null;

		// Cache stale ParserOutput object with a short expiry
		if ( $ok && $renderStatus->hasMessage( 'view-pool-dirty-output' ) ) {
			$outputPage->setCdnMaxage( $wgCdnMaxageStale );
			$outputPage->setLastModified( $pOutput->getCacheTime() );
			$staleReason = $renderStatus->hasMessage( 'view-pool-contention' )
				? $this->getContext()->msg( 'view-pool-contention' )
				: $this->getContext()->msg( 'view-pool-timeout' );
			$outputPage->addHTML( "<!-- parser cache is expired, " .
				"sending anyway due to $staleReason-->\n" );
		}

		if ( !$renderStatus->isOK() ) {
			$this->showViewError( $renderStatus->getWikiText(
				false, 'view-pool-error', $this->getContext()->getLanguage()
			) );
			return;
		}

		if ( $pOutput ) {
			$outputPage->addParserOutput( $pOutput, $textOptions );
		}

		if ( $this->getRevisionRedirectTarget( $rev ) ) {
			$outputPage->addSubtitle( "<span id=\"redirectsub\">" .
				$this->getContext()->msg( 'redirectpagesub' )->parse() . "</span>" );
		}
	}

	/**
	 * @param RevisionRecord $revision
	 * @return null|Title
	 */
	private function getRevisionRedirectTarget( RevisionRecord $revision ) {
		// TODO: find a *good* place for the code that determines the redirect target for
		// a given revision!
		// NOTE: Use main slot content. Compare code in DerivedPageDataUpdater::revisionIsRedirect.
		$content = $revision->getContent( SlotRecord::MAIN );
		return $content ? $content->getRedirectTarget() : null;
	}

	/**
	 * Adjust title for pages with displaytitle, -{T|}- or language conversion
	 * @param ParserOutput $pOutput
	 */
	public function adjustDisplayTitle( ParserOutput $pOutput ) {
		$out = $this->getContext()->getOutput();

		# Adjust the title if it was set by displaytitle, -{T|}- or language conversion
		$titleText = $pOutput->getTitleText();
		if ( strval( $titleText ) !== '' ) {
			$out->setPageTitle( $titleText );
			$out->setDisplayTitle( $titleText );
		}
	}

	/**
	 * Show a diff page according to current request variables. For use within
	 * Article::view() only, other callers should use the DifferenceEngine class.
	 */
	protected function showDiffPage() {
		$request = $this->getContext()->getRequest();
		$user = $this->getContext()->getUser();
		$diff = $request->getVal( 'diff' );
		$rcid = $request->getVal( 'rcid' );
		$diffOnly = $request->getBool( 'diffonly', $user->getOption( 'diffonly' ) );
		$purge = $request->getVal( 'action' ) == 'purge';
		$unhide = $request->getInt( 'unhide' ) == 1;
		$oldid = $this->getOldID();

		$rev = $this->fetchRevisionRecord();

		if ( !$rev ) {
			// T213621: $rev maybe null due to either lack of permission to view the
			// revision or actually not existing. So let's try loading it from the id
			$rev = $this->revisionStore->getRevisionById( $oldid );
			if ( $rev ) {
				// Revision exists but $user lacks permission to diff it.
				// Do nothing here.
				// The $rev will later be used to create standard diff elements however.
			} else {
				$this->getContext()->getOutput()->setPageTitle( wfMessage( 'errorpagetitle' ) );
				$msg = $this->getContext()->msg( 'difference-missing-revision' )
					->params( $oldid )
					->numParams( 1 )
					->parseAsBlock();
				$this->getContext()->getOutput()->addHTML( $msg );
				return;
			}
		}

		$contentHandler = MediaWikiServices::getInstance()
			->getContentHandlerFactory()
			->getContentHandler(
				$rev->getSlot( SlotRecord::MAIN, RevisionRecord::RAW )->getModel()
			);
		$de = $contentHandler->createDifferenceEngine(
			$this->getContext(),
			$oldid,
			$diff,
			$rcid,
			$purge,
			$unhide
		);
		$de->setSlotDiffOptions( [
			'diff-type' => $request->getVal( 'diff-type' )
		] );
		$de->showDiffPage( $diffOnly );

		// Run view updates for the newer revision being diffed (and shown
		// below the diff if not $diffOnly).
		list( $old, $new ) = $de->mapDiffPrevNext( $oldid, $diff );
		// New can be false, convert it to 0 - this conveniently means the latest revision
		$this->mPage->doViewUpdates( $user, (int)$new );
	}

	/**
	 * Get the robot policy to be used for the current view
	 * @param string $action The action= GET parameter
	 * @param ParserOutput|null $pOutput
	 * @return string[] The policy that should be set
	 * @todo actions other than 'view'
	 */
	public function getRobotPolicy( $action, ParserOutput $pOutput = null ) {
		global $wgArticleRobotPolicies, $wgNamespaceRobotPolicies, $wgDefaultRobotPolicy;

		$ns = $this->getTitle()->getNamespace();

		# Don't index user and user talk pages for blocked users (T13443)
		if ( ( $ns === NS_USER || $ns === NS_USER_TALK ) && !$this->getTitle()->isSubpage() ) {
			$specificTarget = null;
			$vagueTarget = null;
			$titleText = $this->getTitle()->getText();
			if ( IPUtils::isValid( $titleText ) ) {
				$vagueTarget = $titleText;
			} else {
				$specificTarget = $titleText;
			}
			if ( DatabaseBlock::newFromTarget( $specificTarget, $vagueTarget ) instanceof DatabaseBlock ) {
				return [
					'index' => 'noindex',
					'follow' => 'nofollow'
				];
			}
		}

		if ( $this->mPage->getId() === 0 || $this->getOldID() ) {
			# Non-articles (special pages etc), and old revisions
			return [
				'index' => 'noindex',
				'follow' => 'nofollow'
			];
		} elseif ( $this->getContext()->getOutput()->isPrintable() ) {
			# Discourage indexing of printable versions, but encourage following
			return [
				'index' => 'noindex',
				'follow' => 'follow'
			];
		} elseif ( $this->getContext()->getRequest()->getInt( 'curid' ) ) {
			# For ?curid=x urls, disallow indexing
			return [
				'index' => 'noindex',
				'follow' => 'follow'
			];
		}

		# Otherwise, construct the policy based on the various config variables.
		$policy = self::formatRobotPolicy( $wgDefaultRobotPolicy );

		if ( isset( $wgNamespaceRobotPolicies[$ns] ) ) {
			# Honour customised robot policies for this namespace
			$policy = array_merge(
				$policy,
				self::formatRobotPolicy( $wgNamespaceRobotPolicies[$ns] )
			);
		}
		if ( $this->getTitle()->canUseNoindex() && is_object( $pOutput ) && $pOutput->getIndexPolicy() ) {
			# __INDEX__ and __NOINDEX__ magic words, if allowed. Incorporates
			# a final sanity check that we have really got the parser output.
			$policy = array_merge(
				$policy,
				[ 'index' => $pOutput->getIndexPolicy() ]
			);
		}

		if ( isset( $wgArticleRobotPolicies[$this->getTitle()->getPrefixedText()] ) ) {
			# (T16900) site config can override user-defined __INDEX__ or __NOINDEX__
			$policy = array_merge(
				$policy,
				self::formatRobotPolicy( $wgArticleRobotPolicies[$this->getTitle()->getPrefixedText()] )
			);
		}

		return $policy;
	}

	/**
	 * Converts a String robot policy into an associative array, to allow
	 * merging of several policies using array_merge().
	 * @param array|string $policy Returns empty array on null/false/'', transparent
	 *   to already-converted arrays, converts string.
	 * @return array 'index' => \<indexpolicy\>, 'follow' => \<followpolicy\>
	 */
	public static function formatRobotPolicy( $policy ) {
		if ( is_array( $policy ) ) {
			return $policy;
		} elseif ( !$policy ) {
			return [];
		}

		$policy = explode( ',', $policy );
		$policy = array_map( 'trim', $policy );

		$arr = [];
		foreach ( $policy as $var ) {
			if ( in_array( $var, [ 'index', 'noindex' ] ) ) {
				$arr['index'] = $var;
			} elseif ( in_array( $var, [ 'follow', 'nofollow' ] ) ) {
				$arr['follow'] = $var;
			}
		}

		return $arr;
	}

	/**
	 * If this request is a redirect view, send "redirected from" subtitle to
	 * the output. Returns true if the header was needed, false if this is not
	 * a redirect view. Handles both local and remote redirects.
	 *
	 * @return bool
	 */
	public function showRedirectedFromHeader() {
		global $wgRedirectSources;

		$context = $this->getContext();
		$outputPage = $context->getOutput();
		$request = $context->getRequest();
		$rdfrom = $request->getVal( 'rdfrom' );

		// Construct a URL for the current page view, but with the target title
		$query = $request->getValues();
		unset( $query['rdfrom'] );
		unset( $query['title'] );
		if ( $this->getTitle()->isRedirect() ) {
			// Prevent double redirects
			$query['redirect'] = 'no';
		}
		$redirectTargetUrl = $this->getTitle()->getLinkURL( $query );

		if ( isset( $this->mRedirectedFrom ) ) {
			// This is an internally redirected page view.
			// We'll need a backlink to the source page for navigation.
			if ( $this->getHookRunner()->onArticleViewRedirect( $this ) ) {
				$redir = $this->linkRenderer->makeKnownLink(
					$this->mRedirectedFrom,
					null,
					[],
					[ 'redirect' => 'no' ]
				);

				$outputPage->addSubtitle( "<span class=\"mw-redirectedfrom\">" .
					$context->msg( 'redirectedfrom' )->rawParams( $redir )->parse()
				. "</span>" );

				// Add the script to update the displayed URL and
				// set the fragment if one was specified in the redirect
				$outputPage->addJsConfigVars( [
					'wgInternalRedirectTargetUrl' => $redirectTargetUrl,
				] );
				$outputPage->addModules( 'mediawiki.action.view.redirect' );

				// Add a <link rel="canonical"> tag
				$outputPage->setCanonicalUrl( $this->getTitle()->getCanonicalURL() );

				// Tell the output object that the user arrived at this article through a redirect
				$outputPage->setRedirectedFrom( $this->mRedirectedFrom );

				return true;
			}
		} elseif ( $rdfrom ) {
			// This is an externally redirected view, from some other wiki.
			// If it was reported from a trusted site, supply a backlink.
			if ( $wgRedirectSources && preg_match( $wgRedirectSources, $rdfrom ) ) {
				$redir = Linker::makeExternalLink( $rdfrom, $rdfrom );
				$outputPage->addSubtitle( "<span class=\"mw-redirectedfrom\">" .
					$context->msg( 'redirectedfrom' )->rawParams( $redir )->parse()
				. "</span>" );

				// Add the script to update the displayed URL
				$outputPage->addJsConfigVars( [
					'wgInternalRedirectTargetUrl' => $redirectTargetUrl,
				] );
				$outputPage->addModules( 'mediawiki.action.view.redirect' );

				return true;
			}
		}

		return false;
	}

	/**
	 * Show a header specific to the namespace currently being viewed, like
	 * [[MediaWiki:Talkpagetext]]. For Article::view().
	 */
	public function showNamespaceHeader() {
		if ( $this->getTitle()->isTalkPage() && !wfMessage( 'talkpageheader' )->isDisabled() ) {
			$this->getContext()->getOutput()->wrapWikiMsg(
				"<div class=\"mw-talkpageheader\">\n$1\n</div>",
				[ 'talkpageheader' ]
			);
		}
	}

	/**
	 * Show the footer section of an ordinary page view
	 */
	public function showViewFooter() {
		# check if we're displaying a [[User talk:x.x.x.x]] anonymous talk page
		if ( $this->getTitle()->getNamespace() === NS_USER_TALK
			&& IPUtils::isValid( $this->getTitle()->getText() )
		) {
			$this->getContext()->getOutput()->addWikiMsg( 'anontalkpagetext' );
		}

		// Show a footer allowing the user to patrol the shown revision or page if possible
		$patrolFooterShown = $this->showPatrolFooter();

		$this->getHookRunner()->onArticleViewFooter( $this, $patrolFooterShown );
	}

	/**
	 * If patrol is possible, output a patrol UI box. This is called from the
	 * footer section of ordinary page views. If patrol is not possible or not
	 * desired, does nothing.
	 *
	 * Side effect: When the patrol link is build, this method will call
	 * OutputPage::preventClickjacking() and load a JS module.
	 *
	 * @return bool
	 */
	public function showPatrolFooter() {
		global $wgUseNPPatrol, $wgUseRCPatrol, $wgUseFilePatrol;

		// Allow hooks to decide whether to not output this at all
		if ( !$this->getHookRunner()->onArticleShowPatrolFooter( $this ) ) {
			return false;
		}

		$outputPage = $this->getContext()->getOutput();
		$user = $this->getContext()->getUser();
		$title = $this->getTitle();
		$rc = false;

		if ( !$this->getContext()->getAuthority()->probablyCan( 'patrol', $title )
			|| !( $wgUseRCPatrol || $wgUseNPPatrol
				|| ( $wgUseFilePatrol && $title->inNamespace( NS_FILE ) ) )
		) {
			// Patrolling is disabled or the user isn't allowed to
			return false;
		}

		if ( $this->mRevisionRecord
			&& !RecentChange::isInRCLifespan( $this->mRevisionRecord->getTimestamp(), 21600 )
		) {
			// The current revision is already older than what could be in the RC table
			// 6h tolerance because the RC might not be cleaned out regularly
			return false;
		}

		// Check for cached results
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$key = $cache->makeKey( 'unpatrollable-page', $title->getArticleID() );
		if ( $cache->get( $key ) ) {
			return false;
		}

		$dbr = wfGetDB( DB_REPLICA );
		$oldestRevisionTimestamp = $dbr->selectField(
			'revision',
			'MIN( rev_timestamp )',
			[ 'rev_page' => $title->getArticleID() ],
			__METHOD__
		);

		// New page patrol: Get the timestamp of the oldest revison which
		// the revision table holds for the given page. Then we look
		// whether it's within the RC lifespan and if it is, we try
		// to get the recentchanges row belonging to that entry
		// (with rc_new = 1).
		$recentPageCreation = false;
		if ( $oldestRevisionTimestamp
			&& RecentChange::isInRCLifespan( $oldestRevisionTimestamp, 21600 )
		) {
			// 6h tolerance because the RC might not be cleaned out regularly
			$recentPageCreation = true;
			$rc = RecentChange::newFromConds(
				[
					'rc_new' => 1,
					'rc_timestamp' => $oldestRevisionTimestamp,
					'rc_namespace' => $title->getNamespace(),
					'rc_cur_id' => $title->getArticleID()
				],
				__METHOD__
			);
			if ( $rc ) {
				// Use generic patrol message for new pages
				$markPatrolledMsg = wfMessage( 'markaspatrolledtext' );
			}
		}

		// File patrol: Get the timestamp of the latest upload for this page,
		// check whether it is within the RC lifespan and if it is, we try
		// to get the recentchanges row belonging to that entry
		// (with rc_type = RC_LOG, rc_log_type = upload).
		$recentFileUpload = false;
		if ( ( !$rc || $rc->getAttribute( 'rc_patrolled' ) ) && $wgUseFilePatrol
			&& $title->getNamespace() === NS_FILE ) {
			// Retrieve timestamp of most recent upload
			$newestUploadTimestamp = $dbr->selectField(
				'image',
				'MAX( img_timestamp )',
				[ 'img_name' => $title->getDBkey() ],
				__METHOD__
			);
			if ( $newestUploadTimestamp
				&& RecentChange::isInRCLifespan( $newestUploadTimestamp, 21600 )
			) {
				// 6h tolerance because the RC might not be cleaned out regularly
				$recentFileUpload = true;
				$rc = RecentChange::newFromConds(
					[
						'rc_type' => RC_LOG,
						'rc_log_type' => 'upload',
						'rc_timestamp' => $newestUploadTimestamp,
						'rc_namespace' => NS_FILE,
						'rc_cur_id' => $title->getArticleID()
					],
					__METHOD__
				);
				if ( $rc ) {
					// Use patrol message specific to files
					$markPatrolledMsg = wfMessage( 'markaspatrolledtext-file' );
				}
			}
		}

		if ( !$recentPageCreation && !$recentFileUpload ) {
			// Page creation and latest upload (for files) is too old to be in RC

			// We definitely can't patrol so cache the information
			// When a new file version is uploaded, the cache is cleared
			$cache->set( $key, '1' );

			return false;
		}

		if ( !$rc ) {
			// Don't cache: This can be hit if the page gets accessed very fast after
			// its creation / latest upload or in case we have high replica DB lag. In case
			// the revision is too old, we will already return above.
			return false;
		}

		if ( $rc->getAttribute( 'rc_patrolled' ) ) {
			// Patrolled RC entry around

			// Cache the information we gathered above in case we can't patrol
			// Don't cache in case we can patrol as this could change
			$cache->set( $key, '1' );

			return false;
		}

		if ( $rc->getPerformerIdentity()->equals( $user ) ) {
			// Don't show a patrol link for own creations/uploads. If the user could
			// patrol them, they already would be patrolled
			return false;
		}

		$outputPage->preventClickjacking();
		if ( $this->getContext()->getAuthority()->isAllowed( 'writeapi' ) ) {
			$outputPage->addModules( 'mediawiki.misc-authed-curate' );
		}

		$link = $this->linkRenderer->makeKnownLink(
			$title,
			$markPatrolledMsg->text(),
			[],
			[
				'action' => 'markpatrolled',
				'rcid' => $rc->getAttribute( 'rc_id' ),
			]
		);

		$outputPage->addHTML(
			"<div class='patrollink' data-mw='interface'>" .
				wfMessage( 'markaspatrolledlink' )->rawParams( $link )->escaped() .
			'</div>'
		);

		return true;
	}

	/**
	 * Purge the cache used to check if it is worth showing the patrol footer
	 * For example, it is done during re-uploads when file patrol is used.
	 * @param int $articleID ID of the article to purge
	 * @since 1.27
	 */
	public static function purgePatrolFooterCache( $articleID ) {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cache->delete( $cache->makeKey( 'unpatrollable-page', $articleID ) );
	}

	/**
	 * Show the error text for a missing article. For articles in the MediaWiki
	 * namespace, show the default message text. To be called from Article::view().
	 */
	public function showMissingArticle() {
		global $wgSend404Code;

		$outputPage = $this->getContext()->getOutput();
		// Whether the page is a root user page of an existing user (but not a subpage)
		$validUserPage = false;

		$title = $this->getTitle();

		$services = MediaWikiServices::getInstance();

		$contextUser = $this->getContext()->getUser();

		# Show info in user (talk) namespace. Does the user exist? Is he blocked?
		if ( $title->getNamespace() === NS_USER
			|| $title->getNamespace() === NS_USER_TALK
		) {
			$rootPart = explode( '/', $title->getText() )[0];
			$user = User::newFromName( $rootPart, false /* allow IP users */ );
			$ip = $this->userNameUtils->isIP( $rootPart );
			$block = DatabaseBlock::newFromTarget( $user, $user );

			if ( $user && $user->isRegistered() && $user->isHidden() &&
				!$this->getContext()->getAuthority()->isAllowed( 'hideuser' )
			) {
				// T120883 if the user is hidden and the viewer cannot see hidden
				// users, pretend like it does not exist at all.
				$user = false;
			}
			if ( !( $user && $user->isRegistered() ) && !$ip ) { # User does not exist
				$outputPage->wrapWikiMsg( "<div class=\"mw-userpage-userdoesnotexist error\">\n\$1\n</div>",
					[ 'userpage-userdoesnotexist-view', wfEscapeWikiText( $rootPart ) ] );
			} elseif (
				$block !== null &&
				$block->getType() != DatabaseBlock::TYPE_AUTO &&
				( $block->isSitewide() || $user->isBlockedFrom( $title ) )
			) {
				// Show log extract if the user is sitewide blocked or is partially
				// blocked and not allowed to edit their user page or user talk page
				LogEventsList::showLogExtract(
					$outputPage,
					'block',
					$services->getNamespaceInfo()->getCanonicalName( NS_USER ) . ':' .
						$block->getTargetName(),
					'',
					[
						'lim' => 1,
						'showIfEmpty' => false,
						'msgKey' => [
							'blocked-notice-logextract',
							$user->getName() # Support GENDER in notice
						]
					]
				);
				$validUserPage = !$title->isSubpage();
			} else {
				$validUserPage = !$title->isSubpage();
			}
		}

		$this->getHookRunner()->onShowMissingArticle( $this );

		# Show delete and move logs if there were any such events.
		# The logging query can DOS the site when bots/crawlers cause 404 floods,
		# so be careful showing this. 404 pages must be cheap as they are hard to cache.
		$dbCache = ObjectCache::getInstance( 'db-replicated' );
		$key = $dbCache->makeKey( 'page-recent-delete', md5( $title->getPrefixedText() ) );
		$isRegistered = $contextUser->isRegistered();
		$sessionExists = $this->getContext()->getRequest()->getSession()->isPersistent();

		if ( $isRegistered || $dbCache->get( $key ) || $sessionExists ) {
			$logTypes = [ 'delete', 'move', 'protect' ];

			$dbr = wfGetDB( DB_REPLICA );

			$conds = [ 'log_action != ' . $dbr->addQuotes( 'revision' ) ];
			// Give extensions a chance to hide their (unrelated) log entries
			$this->getHookRunner()->onArticle__MissingArticleConditions( $conds, $logTypes );
			LogEventsList::showLogExtract(
				$outputPage,
				$logTypes,
				$title,
				'',
				[
					'lim' => 10,
					'conds' => $conds,
					'showIfEmpty' => false,
					'msgKey' => [ $isRegistered || $sessionExists
						? 'moveddeleted-notice'
						: 'moveddeleted-notice-recent'
					]
				]
			);
		}

		if ( !$this->mPage->hasViewableContent() && $wgSend404Code && !$validUserPage ) {
			// If there's no backing content, send a 404 Not Found
			// for better machine handling of broken links.
			$this->getContext()->getRequest()->response()->statusHeader( 404 );
		}

		// Also apply the robot policy for nonexisting pages (even if a 404 was used for sanity)
		$policy = $this->getRobotPolicy( 'view' );
		$outputPage->setIndexPolicy( $policy['index'] );
		$outputPage->setFollowPolicy( $policy['follow'] );

		$hookResult = $this->getHookRunner()->onBeforeDisplayNoArticleText( $this );

		if ( !$hookResult ) {
			return;
		}

		# Show error message
		$oldid = $this->getOldID();
		if ( !$oldid && $title->getNamespace() === NS_MEDIAWIKI && $title->hasSourceText() ) {
			$text = $this->getTitle()->getDefaultMessageText() ?? '';
			$outputPage->addWikiTextAsContent( $text );
		} else {
			if ( $oldid ) {
				// T251066: Try loading the revision from the archive table.
				// Show link to view it if it exists and the user has permission to view it.
				$pa = new PageArchive( $title, $this->getContext()->getConfig() );
				$revRecord = $pa->getArchivedRevisionRecord( $oldid );
				if ( $revRecord && $revRecord->userCan(
					RevisionRecord::DELETED_TEXT,
					$this->getContext()->getAuthority()
				) ) {
					$text = wfMessage(
						'missing-revision-permission', $oldid,
						$revRecord->getTimestamp(),
						$title->getPrefixedDBkey()
					)->plain();
				} else {
					$text = wfMessage( 'missing-revision', $oldid )->plain();
				}

			} elseif ( $this->getContext()->getAuthority()->probablyCan( 'create', $title ) &&
				$this->getContext()->getAuthority()->probablyCan( 'edit', $title )
			) {
				$message = $isRegistered ? 'noarticletext' : 'noarticletextanon';
				$text = wfMessage( $message )->plain();
			} else {
				$text = wfMessage( 'noarticletext-nopermission' )->plain();
			}

			$dir = $this->getContext()->getLanguage()->getDir();
			$lang = $this->getContext()->getLanguage()->getHtmlCode();
			$outputPage->addWikiTextAsInterface( Xml::openElement( 'div', [
				'class' => "noarticletext mw-content-$dir",
				'dir' => $dir,
				'lang' => $lang,
			] ) . "\n$text\n</div>" );
		}
	}

	/**
	 * Show error text for errors generated in Article::view().
	 * @param string $errortext localized wikitext error message
	 */
	private function showViewError( string $errortext ) {
		$outputPage = $this->getContext()->getOutput();
		$outputPage->setPageTitle( $this->getContext()->msg( 'errorpagetitle' ) );
		$outputPage->enableClientCache( false );
		$outputPage->setRobotPolicy( 'noindex,nofollow' );
		$outputPage->clearHTML();
		$outputPage->wrapWikiTextAsInterface( 'errorbox', $errortext );
	}

	/**
	 * If the revision requested for view is deleted, check permissions.
	 * Send either an error message or a warning header to the output.
	 *
	 * @return bool True if the view is allowed, false if not.
	 */
	public function showDeletedRevisionHeader() {
		if ( !$this->mRevisionRecord->isDeleted( RevisionRecord::DELETED_TEXT ) ) {
			// Not deleted
			return true;
		}
		$outputPage = $this->getContext()->getOutput();
		$user = $this->getContext()->getUser();
		// Used in wikilinks, should not contain whitespaces
		$titleText = $this->getTitle()->getPrefixedDBkey();
		// If the user is not allowed to see it...
		if ( !$this->mRevisionRecord->userCan(
			RevisionRecord::DELETED_TEXT,
			$this->getContext()->getAuthority()
		) ) {
			$outputPage->addHtml(
				Html::warningBox(
					$outputPage->msg( 'rev-deleted-text-permission', $titleText )->parse(),
					'plainlinks'
				)
			);

			return false;
		// If the user needs to confirm that they want to see it...
		} elseif ( $this->getContext()->getRequest()->getInt( 'unhide' ) != 1 ) {
			# Give explanation and add a link to view the revision...
			$oldid = intval( $this->getOldID() );
			$link = $this->getTitle()->getFullURL( "oldid={$oldid}&unhide=1" );
			$msg = $this->mRevisionRecord->isDeleted( RevisionRecord::DELETED_RESTRICTED ) ?
				'rev-suppressed-text-unhide' : 'rev-deleted-text-unhide';
			$outputPage->addHtml(
				Html::warningBox(
					$outputPage->msg( $msg, $link )->parse(),
					'plainlinks'
				)
			);

			return false;
		// We are allowed to see...
		} else {
			$msg = $this->mRevisionRecord->isDeleted( RevisionRecord::DELETED_RESTRICTED )
				? [ 'rev-suppressed-text-view', $titleText ]
				: [ 'rev-deleted-text-view', $titleText ];
			$outputPage->addHtml(
				Html::warningBox(
					$outputPage->msg( $msg[0], $msg[1] )->parse(),
					'plainlinks'
				)
			);

			return true;
		}
	}

	/**
	 * Generate the navigation links when browsing through an article revisions
	 * It shows the information as:
	 *   Revision as of \<date\>; view current revision
	 *   \<- Previous version | Next Version -\>
	 *
	 * @param int $oldid Revision ID of this article revision
	 */
	public function setOldSubtitle( $oldid = 0 ) {
		if ( !$this->getHookRunner()->onDisplayOldSubtitle( $this, $oldid ) ) {
			return;
		}

		$context = $this->getContext();
		$unhide = $context->getRequest()->getInt( 'unhide' ) == 1;

		# Cascade unhide param in links for easy deletion browsing
		$extraParams = [];
		if ( $unhide ) {
			$extraParams['unhide'] = 1;
		}

		if ( $this->mRevisionRecord && $this->mRevisionRecord->getId() === $oldid ) {
			$revisionRecord = $this->mRevisionRecord;
		} else {
			$revisionRecord = $this->revisionStore->getRevisionById( $oldid );
		}

		$timestamp = $revisionRecord->getTimestamp();

		$current = ( $oldid == $this->mPage->getLatest() );
		$language = $context->getLanguage();
		$user = $context->getUser();

		$td = $language->userTimeAndDate( $timestamp, $user );
		$tddate = $language->userDate( $timestamp, $user );
		$tdtime = $language->userTime( $timestamp, $user );

		# Show user links if allowed to see them. If hidden, then show them only if requested...
		$userlinks = Linker::revUserTools( $revisionRecord, !$unhide );

		$infomsg = $current && !$context->msg( 'revision-info-current' )->isDisabled()
			? 'revision-info-current'
			: 'revision-info';

		$outputPage = $context->getOutput();
		$outputPage->addModuleStyles( [
			'mediawiki.action.styles',
			'mediawiki.interface.helpers.styles'
		] );

		$revisionUser = $revisionRecord->getUser();
		$revisionInfo = "<div id=\"mw-{$infomsg}\">" .
			$context->msg( $infomsg, $td )
				->rawParams( $userlinks )
				->params(
					$revisionRecord->getId(),
					$tddate,
					$tdtime,
					$revisionUser ? $revisionUser->getName() : ''
				)
				->rawParams( Linker::revComment(
					$revisionRecord,
					true,
					true
				) )
				->parse() .
			"</div>";

		$lnk = $current
			? $context->msg( 'currentrevisionlink' )->escaped()
			: $this->linkRenderer->makeKnownLink(
				$this->getTitle(),
				$context->msg( 'currentrevisionlink' )->text(),
				[],
				$extraParams
			);
		$curdiff = $current
			? $context->msg( 'diff' )->escaped()
			: $this->linkRenderer->makeKnownLink(
				$this->getTitle(),
				$context->msg( 'diff' )->text(),
				[],
				[
					'diff' => 'cur',
					'oldid' => $oldid
				] + $extraParams
			);
		$prevExist = (bool)$this->revisionStore->getPreviousRevision( $revisionRecord );
		$prevlink = $prevExist
			? $this->linkRenderer->makeKnownLink(
				$this->getTitle(),
				$context->msg( 'previousrevision' )->text(),
				[],
				[
					'direction' => 'prev',
					'oldid' => $oldid
				] + $extraParams
			)
			: $context->msg( 'previousrevision' )->escaped();
		$prevdiff = $prevExist
			? $this->linkRenderer->makeKnownLink(
				$this->getTitle(),
				$context->msg( 'diff' )->text(),
				[],
				[
					'diff' => 'prev',
					'oldid' => $oldid
				] + $extraParams
			)
			: $context->msg( 'diff' )->escaped();
		$nextlink = $current
			? $context->msg( 'nextrevision' )->escaped()
			: $this->linkRenderer->makeKnownLink(
				$this->getTitle(),
				$context->msg( 'nextrevision' )->text(),
				[],
				[
					'direction' => 'next',
					'oldid' => $oldid
				] + $extraParams
			);
		$nextdiff = $current
			? $context->msg( 'diff' )->escaped()
			: $this->linkRenderer->makeKnownLink(
				$this->getTitle(),
				$context->msg( 'diff' )->text(),
				[],
				[
					'diff' => 'next',
					'oldid' => $oldid
				] + $extraParams
			);

		$cdel = Linker::getRevDeleteLink(
			$user,
			$revisionRecord,
			$this->getTitle()
		);
		if ( $cdel !== '' ) {
			$cdel .= ' ';
		}

		// the outer div is need for styling the revision info and nav in MobileFrontend
		$outputPage->addSubtitle( "<div class=\"mw-revision warningbox\">" . $revisionInfo .
			"<div id=\"mw-revision-nav\">" . $cdel .
			$context->msg( 'revision-nav' )->rawParams(
				$prevdiff, $prevlink, $lnk, $curdiff, $nextlink, $nextdiff
			)->escaped() . "</div></div>" );
	}

	/**
	 * Return the HTML for the top of a redirect page
	 *
	 * Chances are you should just be using the ParserOutput from
	 * WikitextContent::getParserOutput instead of calling this for redirects.
	 *
	 * @param Title|array $target Destination(s) to redirect
	 * @param bool $appendSubtitle [optional]
	 * @param bool $forceKnown Should the image be shown as a bluelink regardless of existence?
	 * @return string Containing HTML with redirect link
	 *
	 * @deprecated since 1.30
	 */
	public function viewRedirect( $target, $appendSubtitle = true, $forceKnown = false ) {
		$lang = $this->getTitle()->getPageLanguage();
		$out = $this->getContext()->getOutput();
		if ( $appendSubtitle ) {
			$out->addSubtitle( wfMessage( 'redirectpagesub' ) );
		}
		$out->addModuleStyles( 'mediawiki.action.view.redirectPage' );
		return static::getRedirectHeaderHtml( $lang, $target, $forceKnown );
	}

	/**
	 * Return the HTML for the top of a redirect page
	 *
	 * Chances are you should just be using the ParserOutput from
	 * WikitextContent::getParserOutput instead of calling this for redirects.
	 *
	 * @since 1.23
	 * @param Language $lang
	 * @param Title|Title[] $target Destination(s) to redirect
	 * @param bool $forceKnown Should the image be shown as a bluelink regardless of existence?
	 * @return string Containing HTML with redirect link
	 */
	public static function getRedirectHeaderHtml( Language $lang, $target, $forceKnown = false ) {
		if ( !is_array( $target ) ) {
			$target = [ $target ];
		}

		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		$html = '<ul class="redirectText">';
		/** @var Title $title */
		foreach ( $target as $title ) {
			if ( $forceKnown ) {
				$link = $linkRenderer->makeKnownLink(
					$title,
					$title->getFullText(),
					[],
					// Make sure wiki page redirects are not followed
					$title->isRedirect() ? [ 'redirect' => 'no' ] : []
				);
			} else {
				$link = $linkRenderer->makeLink(
					$title,
					$title->getFullText(),
					[],
					// Make sure wiki page redirects are not followed
					$title->isRedirect() ? [ 'redirect' => 'no' ] : []
				);
			}
			$html .= '<li>' . $link . '</li>';
		}
		$html .= '</ul>';

		$redirectToText = wfMessage( 'redirectto' )->inLanguage( $lang )->escaped();

		return '<div class="redirectMsg">' .
			'<p>' . $redirectToText . '</p>' .
			$html .
			'</div>';
	}

	/**
	 * Adds help link with an icon via page indicators.
	 * Link target can be overridden by a local message containing a wikilink:
	 * the message key is: 'namespace-' + namespace number + '-helppage'.
	 * @param string $to Target MediaWiki.org page title or encoded URL.
	 * @param bool $overrideBaseUrl Whether $url is a full URL, to avoid MW.o.
	 * @since 1.25
	 */
	public function addHelpLink( $to, $overrideBaseUrl = false ) {
		$out = $this->getContext()->getOutput();
		$msg = $out->msg( 'namespace-' . $this->getTitle()->getNamespace() . '-helppage' );

		if ( !$msg->isDisabled() ) {
			$title = Title::newFromText( $msg->plain() );
			if ( $title instanceof Title ) {
				$out->addHelpLink( $title->getLocalURL(), true );
			}
		} else {
			$out->addHelpLink( $to, $overrideBaseUrl );
		}
	}

	/**
	 * Handle action=render
	 */
	public function render() {
		$this->getContext()->getRequest()->response()->header( 'X-Robots-Tag: noindex' );
		$this->getContext()->getOutput()->setArticleBodyOnly( true );
		// We later set 'enableSectionEditLinks=false' based on this; also used by ImagePage
		$this->viewIsRenderAction = true;
		$this->view();
	}

	/**
	 * action=protect handler
	 */
	public function protect() {
		$form = new ProtectionForm( $this );
		$form->execute();
	}

	/**
	 * action=unprotect handler (alias)
	 */
	public function unprotect() {
		$this->protect();
	}

	/**
	 * UI entry point for page deletion
	 */
	public function delete() {
		# This code desperately needs to be totally rewritten

		$title = $this->getTitle();
		$context = $this->getContext();
		$user = $context->getUser();
		$request = $context->getRequest();

		# Check permissions
		$permissionStatus = PermissionStatus::newEmpty();
		if ( !$context->getAuthority()->authorizeWrite( 'delete', $title, $permissionStatus ) ) {
			throw new PermissionsError( 'delete', $permissionStatus );
		}

		# Read-only check...
		if ( wfReadOnly() ) {
			throw new ReadOnlyError;
		}

		# Better double-check that it hasn't been deleted yet!
		$this->mPage->loadPageData(
			$request->wasPosted() ? WikiPage::READ_LATEST : WikiPage::READ_NORMAL
		);
		if ( !$this->mPage->exists() ) {
			$deleteLogPage = new LogPage( 'delete' );
			$outputPage = $context->getOutput();
			$outputPage->setPageTitle( $context->msg( 'cannotdelete-title', $title->getPrefixedText() ) );
			$outputPage->wrapWikiMsg( "<div class=\"error mw-error-cannotdelete\">\n$1\n</div>",
					[ 'cannotdelete', wfEscapeWikiText( $title->getPrefixedText() ) ]
				);
			$outputPage->addHTML(
				Xml::element( 'h2', null, $deleteLogPage->getName()->text() )
			);
			LogEventsList::showLogExtract(
				$outputPage,
				'delete',
				$title
			);

			return;
		}

		$deleteReasonList = $request->getText( 'wpDeleteReasonList', 'other' );
		$deleteReason = $request->getText( 'wpReason' );

		if ( $deleteReasonList == 'other' ) {
			$reason = $deleteReason;
		} elseif ( $deleteReason != '' ) {
			// Entry from drop down menu + additional comment
			$colonseparator = wfMessage( 'colon-separator' )->inContentLanguage()->text();
			$reason = $deleteReasonList . $colonseparator . $deleteReason;
		} else {
			$reason = $deleteReasonList;
		}

		if ( $request->wasPosted() && $user->matchEditToken( $request->getVal( 'wpEditToken' ),
			[ 'delete', $this->getTitle()->getPrefixedText() ] )
		) {
			# Flag to hide all contents of the archived revisions

			$suppress = $request->getCheck( 'wpSuppress' ) &&
				$context->getAuthority()->isAllowed( 'suppressrevision' );

			$this->doDelete( $reason, $suppress );

			$this->watchlistManager->setWatch( $request->getCheck( 'wpWatch' ), $context->getAuthority(), $title );

			return;
		}

		// Generate deletion reason
		$hasHistory = false;
		if ( !$reason ) {
			try {
				$reason = $this->getPage()
					->getAutoDeleteReason( $hasHistory );
			} catch ( Exception $e ) {
				# if a page is horribly broken, we still want to be able to
				# delete it. So be lenient about errors here.
				wfDebug( "Error while building auto delete summary: $e" );
				$reason = '';
			}
		}

		// If the page has a history, insert a warning
		if ( $hasHistory ) {
			$title = $this->getTitle();

			// The following can use the real revision count as this is only being shown for users
			// that can delete this page.
			// This, as a side-effect, also makes sure that the following query isn't being run for
			// pages with a larger history, unless the user has the 'bigdelete' right
			// (and is about to delete this page).
			$dbr = wfGetDB( DB_REPLICA );
			$revisions = $edits = (int)$dbr->selectField(
				'revision',
				'COUNT(rev_page)',
				[ 'rev_page' => $title->getArticleID() ],
				__METHOD__
			);

			// @todo i18n issue/patchwork message
			$context->getOutput()->addHTML(
				'<strong class="mw-delete-warning-revisions">' .
				$context->msg( 'historywarning' )->numParams( $revisions )->parse() .
				$context->msg( 'word-separator' )->escaped() . $this->linkRenderer->makeKnownLink(
					$title,
					$context->msg( 'history' )->text(),
					[],
					[ 'action' => 'history' ] ) .
				'</strong>'
			);

			if ( $title->isBigDeletion() ) {
				global $wgDeleteRevisionsLimit;
				$context->getOutput()->wrapWikiMsg( "<div class='error'>\n$1\n</div>\n",
					[
						'delete-warning-toobig',
						$context->getLanguage()->formatNum( $wgDeleteRevisionsLimit )
					]
				);
			}
		}

		$this->confirmDelete( $reason );
	}

	/**
	 * Output deletion confirmation dialog
	 * @todo Move to another file?
	 * @param string $reason Prefilled reason
	 */
	public function confirmDelete( $reason ) {
		wfDebug( "Article::confirmDelete" );

		$title = $this->getTitle();
		$ctx = $this->getContext();
		$outputPage = $ctx->getOutput();
		$outputPage->setPageTitle( wfMessage( 'delete-confirm', $title->getPrefixedText() ) );
		$outputPage->addBacklinkSubtitle( $title );
		$outputPage->setRobotPolicy( 'noindex,nofollow' );
		$outputPage->addModules( 'mediawiki.action.delete' );
		$outputPage->addModuleStyles( 'mediawiki.action.styles' );

		$backlinkCache = $title->getBacklinkCache();
		if ( $backlinkCache->hasLinks( 'pagelinks' ) || $backlinkCache->hasLinks( 'templatelinks' ) ) {
			$outputPage->addHtml(
				Html::warningBox(
					$outputPage->msg( 'deleting-backlinks-warning' )->parse(),
					'plainlinks'
				)
			);
		}

		$subpageQueryLimit = 51;
		$subpages = $title->getSubpages( $subpageQueryLimit );
		$subpageCount = count( $subpages );
		if ( $subpageCount > 0 ) {
			$outputPage->addHtml(
				Html::warningBox(
					$outputPage->msg( 'deleting-subpages-warning', Message::numParam( $subpageCount ) )->parse(),
					'plainlinks'
				)
			);
		}
		$outputPage->addWikiMsg( 'confirmdeletetext' );

		$this->getHookRunner()->onArticleConfirmDelete( $this, $outputPage, $reason );

		$user = $this->getContext()->getUser();
		$services = MediaWikiServices::getInstance();
		$checkWatch = $services->getUserOptionsLookup()->getBoolOption( $user, 'watchdeletion' ) ||
			$this->watchlistManager->isWatched( $user, $title );

		$outputPage->enableOOUI();

		$fields = [];

		$suppressAllowed = $this->getContext()->getAuthority()->isAllowed( 'suppressrevision' );
		$dropDownReason = $ctx->msg( 'deletereason-dropdown' )->inContentLanguage()->text();
		// Add additional specific reasons for suppress
		if ( $suppressAllowed ) {
			$dropDownReason .= "\n" . $ctx->msg( 'deletereason-dropdown-suppress' )
				->inContentLanguage()->text();
		}

		$options = Xml::listDropDownOptions(
			$dropDownReason,
			[ 'other' => $ctx->msg( 'deletereasonotherlist' )->inContentLanguage()->text() ]
		);
		$options = Xml::listDropDownOptionsOoui( $options );

		$fields[] = new OOUI\FieldLayout(
			new OOUI\DropdownInputWidget( [
				'name' => 'wpDeleteReasonList',
				'inputId' => 'wpDeleteReasonList',
				'tabIndex' => 1,
				'infusable' => true,
				'value' => '',
				'options' => $options
			] ),
			[
				'label' => $ctx->msg( 'deletecomment' )->text(),
				'align' => 'top',
			]
		);

		// HTML maxlength uses "UTF-16 code units", which means that characters outside BMP
		// (e.g. emojis) count for two each. This limit is overridden in JS to instead count
		// Unicode codepoints.
		$fields[] = new OOUI\FieldLayout(
			new OOUI\TextInputWidget( [
				'name' => 'wpReason',
				'inputId' => 'wpReason',
				'tabIndex' => 2,
				'maxLength' => CommentStore::COMMENT_CHARACTER_LIMIT,
				'infusable' => true,
				'value' => $reason,
				'autofocus' => true,
			] ),
			[
				'label' => $ctx->msg( 'deleteotherreason' )->text(),
				'align' => 'top',
			]
		);

		if ( $user->isRegistered() ) {
			$fields[] = new OOUI\FieldLayout(
				new OOUI\CheckboxInputWidget( [
					'name' => 'wpWatch',
					'inputId' => 'wpWatch',
					'tabIndex' => 3,
					'selected' => $checkWatch,
				] ),
				[
					'label' => $ctx->msg( 'watchthis' )->text(),
					'align' => 'inline',
					'infusable' => true,
				]
			);
		}
		if ( $suppressAllowed ) {
			$fields[] = new OOUI\FieldLayout(
				new OOUI\CheckboxInputWidget( [
					'name' => 'wpSuppress',
					'inputId' => 'wpSuppress',
					'tabIndex' => 4,
				] ),
				[
					'label' => $ctx->msg( 'revdelete-suppress' )->text(),
					'align' => 'inline',
					'infusable' => true,
				]
			);
		}

		$fields[] = new OOUI\FieldLayout(
			new OOUI\ButtonInputWidget( [
				'name' => 'wpConfirmB',
				'inputId' => 'wpConfirmB',
				'tabIndex' => 5,
				'value' => $ctx->msg( 'deletepage' )->text(),
				'label' => $ctx->msg( 'deletepage' )->text(),
				'flags' => [ 'primary', 'destructive' ],
				'type' => 'submit',
			] ),
			[
				'align' => 'top',
			]
		);

		$fieldset = new OOUI\FieldsetLayout( [
			'label' => $ctx->msg( 'delete-legend' )->text(),
			'id' => 'mw-delete-table',
			'items' => $fields,
		] );

		$form = new OOUI\FormLayout( [
			'method' => 'post',
			'action' => $title->getLocalURL( 'action=delete' ),
			'id' => 'deleteconfirm',
		] );
		$form->appendContent(
			$fieldset,
			new OOUI\HtmlSnippet(
				Html::hidden( 'wpEditToken', $user->getEditToken( [ 'delete', $title->getPrefixedText() ] ) )
			)
		);

		$outputPage->addHTML(
			new OOUI\PanelLayout( [
				'classes' => [ 'deletepage-wrapper' ],
				'expanded' => false,
				'padded' => true,
				'framed' => true,
				'content' => $form,
			] )
		);

		if ( $this->getContext()->getAuthority()->isAllowed( 'editinterface' ) ) {
			$link = '';
			if ( $suppressAllowed ) {
				$link .= $this->linkRenderer->makeKnownLink(
					$ctx->msg( 'deletereason-dropdown-suppress' )->inContentLanguage()->getTitle(),
					$ctx->msg( 'delete-edit-reasonlist-suppress' )->text(),
					[],
					[ 'action' => 'edit' ]
				);
				$link .= $ctx->msg( 'pipe-separator' )->escaped();
			}
			$link .= $this->linkRenderer->makeKnownLink(
				$ctx->msg( 'deletereason-dropdown' )->inContentLanguage()->getTitle(),
				$ctx->msg( 'delete-edit-reasonlist' )->text(),
				[],
				[ 'action' => 'edit' ]
			);
			$outputPage->addHTML( '<p class="mw-delete-editreasons">' . $link . '</p>' );
		}

		$deleteLogPage = new LogPage( 'delete' );
		$outputPage->addHTML( Xml::element( 'h2', null, $deleteLogPage->getName()->text() ) );
		LogEventsList::showLogExtract( $outputPage, 'delete', $title );
	}

	/**
	 * Perform a deletion and output success or failure messages
	 * @param string $reason
	 * @param bool $suppress
	 * @param bool $immediate false allows deleting over time via the job queue
	 * @throws FatalError
	 * @throws MWException
	 */
	public function doDelete( $reason, $suppress = false, $immediate = false ) {
		$error = '';
		$context = $this->getContext();
		$outputPage = $context->getOutput();
		$user = $context->getUser();
		$status = $this->mPage->doDeleteArticleReal(
			$reason, $user, $suppress, null, $error,
			null, [], 'delete', $immediate
		);

		if ( $status->isOK() ) {
			$deleted = $this->getTitle()->getPrefixedText();

			$outputPage->setPageTitle( wfMessage( 'actioncomplete' ) );
			$outputPage->setRobotPolicy( 'noindex,nofollow' );

			if ( $status->isGood() ) {
				$loglink = '[[Special:Log/delete|' . wfMessage( 'deletionlog' )->text() . ']]';
				$outputPage->addWikiMsg( 'deletedtext', wfEscapeWikiText( $deleted ), $loglink );
				$this->getHookRunner()->onArticleDeleteAfterSuccess( $this->getTitle(), $outputPage );
			} else {
				$outputPage->addWikiMsg( 'delete-scheduled', wfEscapeWikiText( $deleted ) );
			}

			$outputPage->returnToMain( false );
		} else {
			$outputPage->setPageTitle(
				wfMessage( 'cannotdelete-title',
					$this->getTitle()->getPrefixedText() )
			);

			if ( $error == '' ) {
				$outputPage->wrapWikiTextAsInterface(
					'error mw-error-cannotdelete',
					$status->getWikiText( false, false, $context->getLanguage() )
				);
				$deleteLogPage = new LogPage( 'delete' );
				$outputPage->addHTML( Xml::element( 'h2', null, $deleteLogPage->getName()->text() ) );

				LogEventsList::showLogExtract(
					$outputPage,
					'delete',
					$this->getTitle()
				);
			} else {
				$outputPage->addHTML( $error );
			}
		}
	}

	/* Caching functions */

	/**
	 * checkLastModified returns true if it has taken care of all
	 * output to the client that is necessary for this request.
	 * (that is, it has sent a cached version of the page)
	 *
	 * @return bool True if cached version send, false otherwise
	 */
	protected function tryFileCache() {
		static $called = false;

		if ( $called ) {
			wfDebug( "Article::tryFileCache(): called twice!?" );
			return false;
		}

		$called = true;
		if ( $this->isFileCacheable() ) {
			$cache = new HTMLFileCache( $this->getTitle(), 'view' );
			if ( $cache->isCacheGood( $this->mPage->getTouched() ) ) {
				wfDebug( "Article::tryFileCache(): about to load file" );
				$cache->loadFromFileCache( $this->getContext() );
				return true;
			} else {
				wfDebug( "Article::tryFileCache(): starting buffer" );
				ob_start( [ &$cache, 'saveToFileCache' ] );
			}
		} else {
			wfDebug( "Article::tryFileCache(): not cacheable" );
		}

		return false;
	}

	/**
	 * Check if the page can be cached
	 * @param int $mode One of the HTMLFileCache::MODE_* constants (since 1.28)
	 * @return bool
	 */
	public function isFileCacheable( $mode = HTMLFileCache::MODE_NORMAL ) {
		$cacheable = false;

		if ( HTMLFileCache::useFileCache( $this->getContext(), $mode ) ) {
			$cacheable = $this->mPage->getId()
				&& !$this->mRedirectedFrom && !$this->getTitle()->isRedirect();
			// Extension may have reason to disable file caching on some pages.
			if ( $cacheable ) {
				$cacheable = $this->getHookRunner()->onIsFileCacheable( $this );
			}
		}

		return $cacheable;
	}

	/** #@- */

	/**
	 * Lightweight method to get the parser output for a page, checking the parser cache
	 * and so on. Doesn't consider most of the stuff that Article::view() is forced to
	 * consider, so it's not appropriate to use there.
	 *
	 * @since 1.16 (r52326) for LiquidThreads
	 *
	 * @param int|null $oldid Revision ID or null
	 * @param UserIdentity|null $user The relevant user
	 * @return ParserOutput|bool ParserOutput or false if the given revision ID is not found
	 */
	public function getParserOutput( $oldid = null, UserIdentity $user = null ) {
		if ( $user === null ) {
			$parserOptions = $this->getParserOptions();
		} else {
			$parserOptions = $this->mPage->makeParserOptions( $user );
		}

		return $this->mPage->getParserOutput( $parserOptions, $oldid );
	}

	/**
	 * Get parser options suitable for rendering the primary article wikitext
	 * @return ParserOptions
	 */
	public function getParserOptions() {
		return $this->mPage->makeParserOptions( $this->getContext() );
	}

	/**
	 * Sets the context this Article is executed in
	 *
	 * @param IContextSource $context
	 * @since 1.18
	 */
	public function setContext( $context ) {
		$this->mContext = $context;
	}

	/**
	 * Gets the context this Article is executed in
	 *
	 * @return IContextSource
	 * @since 1.18
	 */
	public function getContext() {
		if ( $this->mContext instanceof IContextSource ) {
			return $this->mContext;
		} else {
			wfDebug( __METHOD__ . " called and \$mContext is null. " .
				"Return RequestContext::getMain(); for sanity" );
			return RequestContext::getMain();
		}
	}

	/**
	 * @deprecated since 1.35, use Article::getPage() instead
	 *
	 * Use PHP's magic __get handler to handle accessing of
	 * raw WikiPage fields for backwards compatibility
	 *
	 * @param string $fname Field name
	 * @return mixed
	 */
	public function __get( $fname ) {
		wfDeprecatedMsg( "Accessing Article::\$$fname is deprecated since MediaWiki 1.35",
			'1.35' );

		if ( property_exists( $this->mPage, $fname ) ) {
			return $this->mPage->$fname;
		}
		trigger_error( 'Inaccessible property via __get(): ' . $fname, E_USER_NOTICE );
	}

	/**
	 * @deprecated since 1.35, use Article::getPage() instead
	 *
	 * Use PHP's magic __set handler to handle setting of
	 * raw WikiPage fields for backwards compatibility
	 *
	 * @param string $fname Field name
	 * @param mixed $fvalue New value
	 */
	public function __set( $fname, $fvalue ) {
		wfDeprecatedMsg( "Setting Article::\$$fname is deprecated since MediaWiki 1.35",
			'1.35' );

		if ( property_exists( $this->mPage, $fname ) ) {
			$this->mPage->$fname = $fvalue;
		// Note: extensions may want to toss on new fields
		} elseif ( !in_array( $fname, [ 'mContext', 'mPage' ] ) ) {
			$this->mPage->$fname = $fvalue;
		} else {
			trigger_error( 'Inaccessible property via __set(): ' . $fname, E_USER_NOTICE );
		}
	}

	/**
	 * Call to WikiPage function for backwards compatibility.
	 * @see ContentHandler::getActionOverrides
	 * @return array
	 */
	public function getActionOverrides() {
		return $this->mPage->getActionOverrides();
	}

	/**
	 * @deprecated since 1.35, use WikiPage::getTimestamp instead
	 * @see WikiPage::getTimestamp
	 * @return string
	 */
	public function getTimestamp() {
		wfDeprecated( __METHOD__, '1.35' );
		return $this->mPage->getTimestamp();
	}
}
