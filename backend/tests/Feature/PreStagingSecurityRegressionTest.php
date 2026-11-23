<?php

namespace Tests\Feature;

use App\Mail\IdentityLink;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;

class PreStagingSecurityRegressionTest extends TestCase
{
    public function test_boss_catalog_get_and_head_requests_both_require_a_session(): void
    {
        $this->getJson('/api/v1/boss/raw-materials')->assertUnauthorized();
        $this->call('HEAD', '/api/v1/boss/raw-materials')->assertUnauthorized();
    }

    public function test_application_routes_do_not_bind_host_placeholders(): void
    {
        $hostBoundRoutes = array_filter(
            app('router')->getRoutes()->getRoutes(),
            fn (Route $route): bool => $route->getDomain() !== null,
        );

        $this->assertSame([], $hostBoundRoutes);
    }

    public function test_script_name_is_not_stripped_from_a_path_without_a_segment_boundary(): void
    {
        $request = Request::create(
            '/index.phpadmin/api/v1/boss/raw-materials',
            'GET',
            server: ['SCRIPT_NAME' => '/index.php'],
        );

        $this->assertSame('/index.phpadmin/api/v1/boss/raw-materials', $request->getPathInfo());
    }

    public function test_activation_and_password_reset_links_use_the_configured_frontend_host(): void
    {
        config(['app.frontend_url' => 'https://staging.veltra.example']);

        $activation = (new IdentityLink('activation', 'user@example.test', 'activation-token'))->render();
        $reset = (new IdentityLink('reset', 'user@example.test', 'reset-token'))->render();

        $this->assertStringContainsString(
            'https://staging.veltra.example/activate#email=user%40example.test&token=activation-token',
            $activation,
        );
        $this->assertStringContainsString(
            'https://staging.veltra.example/reset-password#email=user%40example.test&token=reset-token',
            $reset,
        );
    }
}
