<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\TestingAccessWrapper;

/**
 * @author Addshore
 *
 * @group Database
 *
 * @covers WatchedItemStore
 */
class WatchedItemStoreIntegrationTest extends MediaWikiTestCase {

	public function setUp() : void {
		parent::setUp();
		self::$users['WatchedItemStoreIntegrationTestUser']
			= new TestUser( 'WatchedItemStoreIntegrationTestUser' );

		$this->setMwGlobals( [
			'wgWatchlistExpiry' => true,
		] );
	}

	private function getUser() {
		return self::$users['WatchedItemStoreIntegrationTestUser']->getUser();
	}

	public function testWatchAndUnWatchItem() {
		$user = $this->getUser();
		$title = Title::newFromText( 'WatchedItemStoreIntegrationTestPage' );
		$store = MediaWikiServices::getInstance()->getWatchedItemStore();
		// Cleanup after previous tests
		$store->removeWatch( $user, $title );
		$initialWatchers = $store->countWatchers( $title );
		$initialUserWatchedItems = $store->countWatchedItems( $user );

		$this->assertFalse(
			$store->isWatched( $user, $title ),
			'Page should not initially be watched'
		);

		$store->addWatch( $user, $title );
		$this->assertTrue(
			$store->isWatched( $user, $title ),
			'Page should be watched'
		);
		$this->assertEquals( $initialUserWatchedItems + 1, $store->countWatchedItems( $user ) );
		$watchedItemsForUser = $store->getWatchedItemsForUser( $user );
		$this->assertCount( $initialUserWatchedItems + 1, $watchedItemsForUser );
		$watchedItemsForUserHasExpectedItem = false;
		foreach ( $watchedItemsForUser as $watchedItem ) {
			if (
				$watchedItem->getUser()->equals( $user ) &&
				$watchedItem->getLinkTarget() == $title->getTitleValue()
			) {
				$watchedItemsForUserHasExpectedItem = true;
			}
		}
		$this->assertTrue(
			$watchedItemsForUserHasExpectedItem,
			'getWatchedItemsForUser should contain the page'
		);
		$this->assertEquals( $initialWatchers + 1, $store->countWatchers( $title ) );
		$this->assertEquals(
			$initialWatchers + 1,
			$store->countWatchersMultiple( [ $title ] )[$title->getNamespace()][$title->getDBkey()]
		);
		$this->assertEquals(
			[ 0 => [ 'WatchedItemStoreIntegrationTestPage' => $initialWatchers + 1 ] ],
			$store->countWatchersMultiple( [ $title ], [ 'minimumWatchers' => $initialWatchers + 1 ] )
		);
		$this->assertEquals(
			[ 0 => [ 'WatchedItemStoreIntegrationTestPage' => 0 ] ],
			$store->countWatchersMultiple( [ $title ], [ 'minimumWatchers' => $initialWatchers + 2 ] )
		);
		$this->assertEquals(
			[ $title->getNamespace() => [ $title->getDBkey() => null ] ],
			$store->getNotificationTimestampsBatch( $user, [ $title ] )
		);

		$store->removeWatch( $user, $title );
		$this->assertFalse(
			$store->isWatched( $user, $title ),
			'Page should be unwatched'
		);
		$this->assertEquals( $initialUserWatchedItems, $store->countWatchedItems( $user ) );
		$watchedItemsForUser = $store->getWatchedItemsForUser( $user );
		$this->assertCount( $initialUserWatchedItems, $watchedItemsForUser );
		$watchedItemsForUserHasExpectedItem = false;
		foreach ( $watchedItemsForUser as $watchedItem ) {
			if (
				$watchedItem->getUser()->equals( $user ) &&
				$watchedItem->getLinkTarget() == $title->getTitleValue()
			) {
				$watchedItemsForUserHasExpectedItem = true;
			}
		}
		$this->assertFalse(
			$watchedItemsForUserHasExpectedItem,
			'getWatchedItemsForUser should not contain the page'
		);
		$this->assertEquals( $initialWatchers, $store->countWatchers( $title ) );
		$this->assertEquals(
			$initialWatchers,
			$store->countWatchersMultiple( [ $title ] )[$title->getNamespace()][$title->getDBkey()]
		);
		$this->assertEquals(
			[ $title->getNamespace() => [ $title->getDBkey() => false ] ],
			$store->getNotificationTimestampsBatch( $user, [ $title ] )
		);
	}

	public function testWatchAndUnWatchItemWithExpiry(): void {
		$user = $this->getUser();
		$title = Title::newFromText( 'WatchedItemStoreIntegrationTestPage' );
		$store = MediaWikiServices::getInstance()->getWatchedItemStore();
		$initialUserWatchedItems = $store->countWatchedItems( $user );

		$store->addWatch( $user, $title, '20300101000000' );
		$this->assertSame(
			'20300101000000',
			$store->loadWatchedItem( $user, $title )->getExpiry()
		);
		$this->assertEquals( $initialUserWatchedItems + 1, $store->countWatchedItems( $user ) );

		// Invalid expiry, nothing should change.
		$store->addWatch( $user, $title, 'invalid expiry' );
		$this->assertSame(
			'20300101000000',
			$store->loadWatchedItem( $user, $title )->getExpiry()
		);
		$this->assertEquals( $initialUserWatchedItems + 1, $store->countWatchedItems( $user ) );

		// Changed to infinity, so expiry row should be removed.
		$store->addWatch( $user, $title, 'infinity' );
		$this->assertNull(
			$store->loadWatchedItem( $user, $title )->getExpiry()
		);
		$this->assertEquals( $initialUserWatchedItems + 1, $store->countWatchedItems( $user ) );

		// Updating to a valid expiry.
		$store->addWatch( $user, $title, '1 month' );
		$this->assertLessThanOrEqual(
			strtotime( '1 month' ),
			wfTimestamp(
				TS_UNIX,
				$store->loadWatchedItem( $user, $title )->getExpiry()
			)
		);
		$this->assertEquals( $initialUserWatchedItems + 1, $store->countWatchedItems( $user ) );

		// Expiry in the past, should not be considered watched.
		$store->addWatch( $user, $title, '20090101000000' );
		$this->assertEquals( $initialUserWatchedItems, $store->countWatchedItems( $user ) );

		// Test isWatch(), which would normally pull from the cache. In this case
		// the cache should bust and return false since the item has expired.
		$this->assertFalse(
			$store->isWatched( $user, $title )
		);
	}

	public function testWatchAndUnwatchMultipleWithExpiry(): void {
		$user = $this->getUser();
		$title1 = Title::newFromText( 'WatchedItemStoreIntegrationTestPage1' );
		$title2 = Title::newFromText( 'WatchedItemStoreIntegrationTestPage1' );
		$store = MediaWikiServices::getInstance()->getWatchedItemStore();

		$timestamp = '20500101000000';
		$store->addWatchBatchForUser( $user, [ $title1, $title2 ], $timestamp );

		$this->assertSame(
			$timestamp,
			$store->loadWatchedItem( $user, $title1 )->getExpiry()
		);
		$this->assertSame(
			$timestamp,
			$store->loadWatchedItem( $user, $title2 )->getExpiry()
		);

		// Clear expiries.
		$store->addWatchBatchForUser( $user, [ $title1, $title2 ], 'infinity' );

		$this->assertNull(
			$store->loadWatchedItem( $user, $title1 )->getExpiry()
		);
		$this->assertNull(
			$store->loadWatchedItem( $user, $title2 )->getExpiry()
		);
	}

	public function testWatchBatchAndClearItems() {
		$user = $this->getUser();
		$title1 = Title::newFromText( 'WatchedItemStoreIntegrationTestPage1' );
		$title2 = Title::newFromText( 'WatchedItemStoreIntegrationTestPage2' );
		$store = MediaWikiServices::getInstance()->getWatchedItemStore();

		$store->addWatchBatchForUser( $user, [ $title1, $title2 ] );

		$this->assertTrue( $store->isWatched( $user, $title1 ) );
		$this->assertTrue( $store->isWatched( $user, $title2 ) );

		$store->clearUserWatchedItems( $user );

		$this->assertFalse( $store->isWatched( $user, $title1 ) );
		$this->assertFalse( $store->isWatched( $user, $title2 ) );
	}

	public function testUpdateResetAndSetNotificationTimestamp() {
		$user = $this->getUser();
		$otherUser = ( new TestUser( 'WatchedItemStoreIntegrationTestUser_otherUser' ) )->getUser();
		$title = Title::newFromText( 'WatchedItemStoreIntegrationTestPage' );
		$store = MediaWikiServices::getInstance()->getWatchedItemStore();
		$store->addWatch( $user, $title );
		$this->assertNull( $store->loadWatchedItem( $user, $title )->getNotificationTimestamp() );
		$initialVisitingWatchers = $store->countVisitingWatchers( $title, '20150202020202' );
		$initialUnreadNotifications = $store->countUnreadNotifications( $user );

		$store->updateNotificationTimestamp( $otherUser, $title, '20150202010101' );
		$this->assertSame(
			'20150202010101',
			$store->loadWatchedItem( $user, $title )->getNotificationTimestamp()
		);
		$this->assertEquals(
			[ $title->getNamespace() => [ $title->getDBkey() => '20150202010101' ] ],
			$store->getNotificationTimestampsBatch( $user, [ $title ] )
		);
		$this->assertEquals(
			$initialVisitingWatchers - 1,
			$store->countVisitingWatchers( $title, '20150202020202' )
		);
		$this->assertEquals(
			$initialVisitingWatchers - 1,
			$store->countVisitingWatchersMultiple(
				[ [ $title, '20150202020202' ] ]
			)[$title->getNamespace()][$title->getDBkey()]
		);
		$this->assertEquals(
			$initialUnreadNotifications + 1,
			$store->countUnreadNotifications( $user )
		);
		$this->assertSame(
			true,
			$store->countUnreadNotifications( $user, $initialUnreadNotifications + 1 )
		);

		$this->assertTrue( $store->resetNotificationTimestamp( $user, $title ) );
		$this->assertNull( $store->getWatchedItem( $user, $title )->getNotificationTimestamp() );
		$this->assertEquals(
			[ $title->getNamespace() => [ $title->getDBkey() => null ] ],
			$store->getNotificationTimestampsBatch( $user, [ $title ] )
		);

		// Run the job queue
		JobQueueGroup::destroySingletons();
		$jobs = new RunJobs;
		$jobs->loadParamsAndArgs( null, [ 'quiet' => true ], null );
		$jobs->execute();

		$this->assertEquals(
			$initialVisitingWatchers,
			$store->countVisitingWatchers( $title, '20150202020202' )
		);
		$this->assertEquals(
			$initialVisitingWatchers,
			$store->countVisitingWatchersMultiple(
				[ [ $title, '20150202020202' ] ]
			)[$title->getNamespace()][$title->getDBkey()]
		);
		$this->assertEquals(
			[ 0 => [ 'WatchedItemStoreIntegrationTestPage' => $initialVisitingWatchers ] ],
			$store->countVisitingWatchersMultiple(
				[ [ $title, '20150202020202' ] ], $initialVisitingWatchers
			)
		);
		$this->assertEquals(
			[ 0 => [ 'WatchedItemStoreIntegrationTestPage' => 0 ] ],
			$store->countVisitingWatchersMultiple(
				[ [ $title, '20150202020202' ] ], $initialVisitingWatchers + 1
			)
		);

		// setNotificationTimestampsForUser specifying a title
		$this->assertTrue(
			$store->setNotificationTimestampsForUser( $user, '20100202020202', [ $title ] )
		);
		$this->assertSame(
			'20100202020202',
			$store->getWatchedItem( $user, $title )->getNotificationTimestamp()
		);

		// setNotificationTimestampsForUser not specifying a title
		// This will try to use a DeferredUpdate; disable that
		$mockCallback = function ( $callback ) {
			$callback();
		};
		$scopedOverride = $store->overrideDeferredUpdatesAddCallableUpdateCallback( $mockCallback );
		$this->assertTrue(
			$store->setNotificationTimestampsForUser( $user, '20110202020202' )
		);
		// Because the operation above is normally deferred, it doesn't clear the cache
		// Clear the cache manually
		$wrappedStore = TestingAccessWrapper::newFromObject( $store );
		$wrappedStore->uncacheUser( $user );
		$this->assertSame(
			'20110202020202',
			$store->getWatchedItem( $user, $title )->getNotificationTimestamp()
		);
	}

	public function testDuplicateAllAssociatedEntries() {
		$user = $this->getUser();
		$titleOld = Title::newFromText( 'WatchedItemStoreIntegrationTestPageOld' );
		$titleNew = Title::newFromText( 'WatchedItemStoreIntegrationTestPageNew' );
		$store = MediaWikiServices::getInstance()->getWatchedItemStore();
		$store->addWatch( $user, $titleOld->getSubjectPage() );
		$store->addWatch( $user, $titleOld->getTalkPage() );
		// Cleanup after previous tests
		$store->removeWatch( $user, $titleNew->getSubjectPage() );
		$store->removeWatch( $user, $titleNew->getTalkPage() );

		$store->duplicateAllAssociatedEntries( $titleOld, $titleNew );

		$this->assertTrue( $store->isWatched( $user, $titleOld->getSubjectPage() ) );
		$this->assertTrue( $store->isWatched( $user, $titleOld->getTalkPage() ) );
		$this->assertTrue( $store->isWatched( $user, $titleNew->getSubjectPage() ) );
		$this->assertTrue( $store->isWatched( $user, $titleNew->getTalkPage() ) );
	}

	public function testRemoveExpired() {
		$store = MediaWikiServices::getInstance()->getWatchedItemStore();

		// Clear out any expired rows, to start from a known point.
		$store->removeExpired( 10 );
		$this->assertSame( 0, $store->countExpired() );

		// Add three pages, two of which have already expired.
		$user = $this->getUser();
		$store->addWatch( $user, Title::newFromText( 'P1' ), '2020-01-25' );
		$store->addWatch( $user, Title::newFromText( 'P2' ), '20200101000000' );
		$store->addWatch( $user, Title::newFromText( 'P3' ), '1 month' );

		// Test that they can be counted and removed correctly.
		$this->assertSame( 2, $store->countExpired() );
		$store->removeExpired( 1 );
		$this->assertSame( 1, $store->countExpired() );
	}

	public function testRemoveOrphanedExpired() {
		$store = MediaWikiServices::getInstance()->getWatchedItemStore();
		// Clear out any expired rows, to start from a known point.
		$store->removeExpired( 10 );

		// Manually insert some orphaned non-expired rows.
		$orphanRows = [
			[ 'we_item' => '100000', 'we_expiry' => $this->db->timestamp( '30300101000000' ) ],
			[ 'we_item' => '100001', 'we_expiry' => $this->db->timestamp( '30300101000000' ) ],
		];
		$this->db->insert( 'watchlist_expiry', $orphanRows, __METHOD__ );
		$initialRowCount = $this->db->selectRowCount( 'watchlist_expiry', '*', [], __METHOD__ );

		// Make sure the orphans aren't removed if it's not requested.
		$store->removeExpired( 10, false );
		$this->assertSame(
			$initialRowCount,
			$this->db->selectRowCount( 'watchlist_expiry', '*', [], __METHOD__ )
		);

		// Make sure they are removed when requested.
		$store->removeExpired( 10, true );
		$this->assertSame(
			$initialRowCount - 2,
			$this->db->selectRowCount( 'watchlist_expiry', '*', [], __METHOD__ )
		);
	}
}
