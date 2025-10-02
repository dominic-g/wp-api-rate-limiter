<?php
/**
 * Unit tests for the PolicyEngine class.
 *
 * @package WP_API_Rate_Limiter
 * @since 1.0.0
 */

namespace WPRateLimiter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPRateLimiter\Core\PolicyEngine;

class PolicyEngineTest extends TestCase {

    private $policy_engine;

    protected function setUp(): void {
        parent::setUp();
        $this->policy_engine = new PolicyEngine();
    }

    public function testBlockRestRequestReturnsCorrectResponse() {
        $retry_after = 60;
        $response = $this->policy_engine->block_rest_request( $retry_after );

        $this->assertInstanceOf( 'WP_REST_Response', $response );
        $this->assertEquals( 429, $response->get_status() );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'code', $data );
        $this->assertEquals( 'rlm_rate_limit_exceeded', $data['code'] );
        $this->assertArrayHasKey( 'message', $data );
        $this->assertStringContainsString( 'exceeded', $data['message'] );
        $this->assertArrayHasKey( 'data', $data );
        $this->assertArrayHasKey( 'retry_after', $data['data'] );
        $this->assertEquals( $retry_after, $data['data']['retry_after'] );

        $headers = $response->get_headers();
        $this->assertArrayHasKey( 'Retry-After', $headers );
        $this->assertEquals( (string) $retry_after, $headers['Retry-After'] );
        $this->assertArrayHasKey( 'X-RateLimit-Limit', $headers );
        $this->assertArrayHasKey( 'X-RateLimit-Remaining', $headers );
        $this->assertEquals( 0, $headers['X-RateLimit-Remaining'] );
        $this->assertArrayHasKey( 'X-RateLimit-Reset', $headers );
        // Check that X-RateLimit-Reset is roughly in the future.
        $this->assertGreaterThanOrEqual( time(), (int) $headers['X-RateLimit-Reset'] );
    }

    public function testBlockAdminAjaxRequestSendsCorrectHeadersAndExits() {
        $retry_after = 30;

        // PHPUnit cannot easily test wp_die() or header() directly
        // without mocking global functions or using advanced techniques.
        // For now, we'll assert that it attempts to exit and sets headers.
        // In a real integration test, you'd test the actual HTTP response.

        // Expect wp_die() to be called.
        $this->expectOutputString(
            json_encode(
                [
                    'success' => false,
                    'data'    => [
                        'message'     => __( 'You have exceeded the API rate limit. Please try again later.', 'wp-api-rate-limiter' ),
                        'retry_after' => $retry_after,
                    ],
                ]
            )
        );
        $this->expectOutputRegex( '/Rate limit exceeded/' ); // Check for message in output.

        // Use runkit7 or vfsStream for more advanced testing of global functions,
        // or simply ensure this method terminates execution with wp_die().
        // For now, we'll use a simple approach to catch output and expected exit.

        // Mock wp_die to not exit, allowing further execution within the test.
        // This is usually done in bootstrap or a test helper.
        if ( ! function_exists( 'wp_die' ) ) {
            function wp_die( $message = '', $title = '', $args = '' ) {
                echo $message; // Output the message to catch it.
                throw new \Exception( 'wp_die called' ); // Simulate exit.
            }
        }
        // And also for header function.
        if ( ! function_exists( 'header' ) ) {
            function header( $string, $replace = true, $http_response_code = null ) {
                // Collect headers for assertion.
                global $_mocked_headers;
                $_mocked_headers[] = $string;
                // PHPUnit provides utilities for header testing, but a simple global array can work.
            }
        }
        global $_mocked_headers;
        $_mocked_headers = []; // Reset for this test.

        try {
            $this->policy_engine->block_admin_ajax_request( $retry_after );
        } catch ( \Exception $e ) {
            $this->assertEquals( 'wp_die called', $e->getMessage() );
        }

        // Assert headers were set (basic check).
        $this->assertContains( 'Content-Type: application/json', $_mocked_headers );
        $this->assertContains( "Retry-After: $retry_after", $_mocked_headers );
        $this->assertContains( 'X-RateLimit-Remaining: 0', $_mocked_headers );
        // Note: X-RateLimit-Reset's value will be dynamic, harder to assert precisely here.
    }
}