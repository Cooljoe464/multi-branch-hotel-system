<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

function getInertiaVersion(): string
{
    $manifest = public_path('build/manifest.json');

    if (file_exists($manifest)) {
        return hash('xxh128', file_get_contents($manifest));
    }

    return '';
}

it('returns 404 for non-existent routes', function () {
    $this->actingAs($this->user)
        ->get('/non-existent-route-that-does-not-exist')
        ->assertStatus(404);
});

it('returns 403 for forbidden access', function () {
    Route::get('test-forbidden-status', function () {
        abort(403);
    })->middleware(['web', 'auth']);

    $this->actingAs($this->user)
        ->get('test-forbidden-status')
        ->assertStatus(403);
});

it('returns 500 for server errors', function () {
    Route::get('test-server-error-status', function () {
        abort(500);
    })->middleware(['web', 'auth']);

    $this->actingAs($this->user)
        ->get('test-server-error-status')
        ->assertStatus(500);
});

it('returns 404 for non-existent API routes', function () {
    $this->actingAs($this->user)
        ->get('/api/non-existent-api-route', ['Accept' => 'application/json'])
        ->assertStatus(404);
});

it('does not include Inertia component in non-Inertia error responses', function () {
    $response = $this->actingAs($this->user)
        ->get('/non-existent-route-that-does-not-exist');

    $response->assertStatus(404);
    $content = $response->getContent();
    $this->assertStringNotContainsString('errors/404', $content ?? '');
});

it('handles 403 abort with correct status code', function () {
    Route::get('test-abort-403', function () {
        abort(403, 'Forbidden');
    })->middleware(['web', 'auth']);

    $this->actingAs($this->user)
        ->get('test-abort-403')
        ->assertStatus(403)
        ->assertSee('403');
});

it('handles 500 abort with correct status code', function () {
    Route::get('test-abort-500', function () {
        abort(500, 'Server Error');
    })->middleware(['web', 'auth']);

    $this->actingAs($this->user)
        ->get('test-abort-500')
        ->assertStatus(500)
        ->assertSee('500');
});

it('renders 404 Inertia error page via exception handler', function () {
    Route::get('test-inertia-404', function () {
        abort(404);
    })->middleware(['web', 'auth']);

    $this->actingAs($this->user)
        ->get('test-inertia-404', [
            'X-Inertia' => true,
            'X-Inertia-Version' => getInertiaVersion(),
        ])
        ->assertStatus(404)
        ->assertJson(['component' => 'errors/404']);
});

it('renders 403 Inertia error page via exception handler', function () {
    Route::get('test-inertia-403', function () {
        abort(403);
    })->middleware(['web', 'auth']);

    $this->actingAs($this->user)
        ->get('test-inertia-403', [
            'X-Inertia' => true,
            'X-Inertia-Version' => getInertiaVersion(),
        ])
        ->assertStatus(403)
        ->assertJson(['component' => 'errors/403']);
});

it('renders 500 Inertia error page via exception handler', function () {
    Route::get('test-inertia-500', function () {
        abort(500);
    })->middleware(['web', 'auth']);

    $this->actingAs($this->user)
        ->get('test-inertia-500', [
            'X-Inertia' => true,
            'X-Inertia-Version' => getInertiaVersion(),
        ])
        ->assertStatus(500)
        ->assertJson(['component' => 'errors/500']);
});
