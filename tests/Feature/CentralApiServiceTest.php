<?php

namespace Tests\Feature;

use App\Exceptions\CentralApiAuthenticationException;
use App\Exceptions\CentralApiException;
use App\Services\CentralApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CentralApiServiceTest extends TestCase
{
    private function kindOf(callable $call): CentralApiException
    {
        try {
            $call();
        } catch (CentralApiException $e) {
            return $e;
        }
        $this->fail('No CentralApiException was thrown.');
    }

    public function test_each_status_maps_to_a_kind(): void
    {
        $api = app(CentralApiService::class);
        foreach ([403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 410 => 'gone', 422 => 'invalid', 500 => 'server'] as $status => $kind) {
            Http::fake(["*/s{$status}" => Http::response(['message' => "Status {$status}"], $status)]);
            $e = $this->kindOf(fn () => $api->send('get', "s{$status}", 'token'));
            $this->assertSame($kind, $e->kind, "status {$status}");
            $this->assertSame($status, $e->status);
        }
    }

    public function test_a_401_is_the_authentication_exception(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);
        $e = $this->kindOf(fn () => app(CentralApiService::class)->send('get', 'auth/me', 'token'));
        $this->assertInstanceOf(CentralApiAuthenticationException::class, $e);
        $this->assertSame(CentralApiException::UNAUTHENTICATED, $e->kind);
    }

    public function test_no_connection_is_offline(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $e = $this->kindOf(fn () => app(CentralApiService::class)->send('get', 'auth/me', 'token'));
        $this->assertTrue($e->isOffline());
    }

    public function test_a_422_keeps_field_errors_and_a_409_keeps_its_data(): void
    {
        Http::fake(['*/bad' => Http::response(['message' => 'Bad.', 'errors' => ['purok_id' => ['Wrong purok.']]], 422)]);
        $e = $this->kindOf(fn () => app(CentralApiService::class)->send('post', 'bad', 'token', ['a' => 1]));
        $this->assertSame('Bad. Wrong purok.', $e->getMessage());
        $this->assertSame(['purok_id' => ['Wrong purok.']], $e->errors());

        Http::fake(['*/decide' => Http::response(['message' => 'Decide.', 'data' => ['possible_duplicates' => [['family_id' => 5]]]], 409)]);
        $e = $this->kindOf(fn () => app(CentralApiService::class)->send('post', 'decide', 'token'));
        $this->assertSame(5, $e->data()['possible_duplicates'][0]['family_id']);
    }

    public function test_requests_carry_the_bearer_token_and_json_body(): void
    {
        Http::fake(['*' => Http::response(['data' => ['ok' => true]])]);
        app(CentralApiService::class)->send('post', 'puroks', 'abc', ['name' => 'Purok 1']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer abc')
            && $request['name'] === 'Purok 1'
            && str_ends_with($request->url(), '/puroks'));
    }
}
