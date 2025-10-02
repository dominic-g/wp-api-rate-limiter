<?php
/**
 * Unit tests for the RulesEngine class.
 *
 * @package WP_API_Rate_Limiter
 * @since 1.0.0
 */

namespace WPRateLimiter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPRateLimiter\Core\RulesEngine;

class RulesEngineTest extends TestCase {

    private $rules_engine;

    protected function setUp(): void {
        parent::setUp();
        // Clear the object cache to ensure clean state for each test.
        wp_cache_flush();

        $this->rules_engine = new RulesEngine();

        // Set default limits in options for testing, as they would be during activation.
        update_option( RulesEngine::RLM_GLOBAL_UNAUTH_LIMIT_OPTION, ['count' => 5, 'seconds' => 60] );
        update_option( RulesEngine::RLM_GLOBAL_AUTH_LIMIT_OPTION, ['count' => 10, 'seconds' => 60] );
    }

    protected function tearDown(): void {
        parent::tearDown();
        wp_cache_flush(); // Clean up cache after tests.
        delete_option( RulesEngine::RLM_GLOBAL_UNAUTH_LIMIT_OPTION );
        delete_option( RulesEngine::RLM_GLOBAL_AUTH_LIMIT_OPTION );
    }

    public function testUnauthenticatedRequestBelowLimit() {
        $ip       = '192.168.1.1';
        $user_id  = null;
        $endpoint = '/wp/v2/posts';

        // Make 4 requests, which is below the default unauthenticated limit of 5.
        for ( $i = 0; $i < 4; $i++ ) {
            $result = $this->rules_engine->check_request( $ip, $user_id, $endpoint );
            $this->assertFalse( $result['blocked'], "Request should not be blocked on iteration $i." );
            $this->assertEquals( 0, $result['retry_after'], "Retry-After should be 0 when not blocked on iteration $i." );
        }
    }

    public function testUnauthenticatedRequestExceedsLimit() {
        $ip       = '192.168.1.2';
        $user_id  = null;
        $endpoint = '/wp/v2/comments';

        // Make 5 requests, reaching the limit.
        for ( $i = 0; $i < 5; $i++ ) {
            $result = $this->rules_engine->check_request( $ip, $user_id, $endpoint );
            $this->assertFalse( $result['blocked'], "Request should not be blocked on iteration $i." );
        }

        // The 6th request should be blocked.
        $result = $this->rules_engine->check_request( $ip, $user_id, $endpoint );
        $this->assertTrue( $result['blocked'], 'Request should be blocked after exceeding limit.' );
        $this->assertGreaterThan( 0, $result['retry_after'], 'Retry-After should be greater than 0 when blocked.' );
    }

    public function testAuthenticatedRequestBelowLimit() {
        $ip       = '192.168.1.3';
        $user_id  = 123; // Dummy user ID
        $endpoint = '/wp/v2/users';

        // Make 9 requests, which is below the default authenticated limit of 10.
        for ( $i = 0; $i < 9; $i++ ) {
            $result = $this->rules_engine->check_request( $ip, $user_id, $endpoint );
            $this->assertFalse( $result['blocked'], "Authenticated request should not be blocked on iteration $i." );
        }
    }

    public function testAuthenticatedRequestExceedsLimit() {
        $ip       = '192.168.1.4';
        $user_id  = 456; // Dummy user ID
        $endpoint = '/wp/v2/media';

        // Make 10 requests, reaching the limit.
        for ( $i = 0; $i < 10; $i++ ) {
            $result = $this->rules_engine->check_request( $ip, $user_id, $endpoint );
            $this->assertFalse( $result['blocked'], "Authenticated request should not be blocked on iteration $i." );
        }

        // The 11th request should be blocked.
        $result = $this->rules_engine->check_request( $ip, $user_id, $endpoint );
        $this->assertTrue( $result['blocked'], 'Authenticated request should be blocked after exceeding limit.' );
        $this->assertGreaterThan( 0, $result['retry_after'], 'Retry-After should be greater than 0 when blocked.' );
    }

    public function testLimitsResetAfterTimeWindow() {
        $ip       = '192.168.1.5';
        $user_id  = null;
        $endpoint = '/wp/v2/tags';

        // Temporarily set a very short limit for testing.
        update_option( RulesEngine::RLM_GLOBAL_UNAUTH_LIMIT_OPTION, ['count' => 2, 'seconds' => 2] ); // 2 requests per 2 seconds

        // First request in window 1.
        $result = $this->rules_engine->check_request( $ip, $user_id, $endpoint );
        $this->assertFalse( $result['blocked'] );

        // Second request in window 1.
        $result = $this->rules_engine->check_request( $ip, $user_id, $endpoint );
        $this->assertFalse( $result['blocked'] );

        // Third request in window 1 should be blocked.
        $result = $this->rules_engine->check_request( $ip, $user_id, $endpoint );
        $this->assertTrue( $result['blocked'] );
        $this->assertGreaterThan( 0, $result['retry_after'] );

        // Advance time past the window.
        sleep(3); // Sleep for more than 'seconds'

        // First request in new window should not be blocked.
        $result = $this->rules_engine->check_request( $ip, $user_id, $endpoint );
        $this->assertFalse( $result['blocked'] );
    }
}