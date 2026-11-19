<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use phpseclib3\Crypt\EC;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Server::flushIdentityMap();
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $ecKey = EC::createKey('Ed25519');
    $privateKeyContent = $ecKey->toString('OpenSSH');

    $privateKey = PrivateKey::create([
        'name' => 'test-key',
        'private_key' => $privateKeyContent,
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    ServerSetting::create([
        'server_id' => $this->server->id,
        'wildcard_domain' => 'http://127.0.0.1.sslip.io',
    ]);

    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->uuid(),
    ]);
});

test('applicationParser preserves networks defined in map form with null or bare key', function () {
    $dockerCompose = <<<'YAML'
services:
  app-list:
    image: alpine:3.24
    networks:
      - custom-net
  app-null:
    image: alpine:3.24
    networks:
      custom-net: null
  app-bare:
    image: alpine:3.24
    networks:
      custom-net:
  app-empty-map:
    image: alpine:3.24
    networks:
      custom-net: {}
  app-map-config:
    image: alpine:3.24
    networks:
      custom-net:
        aliases:
          - my-alias
networks:
  custom-net:
    external: true
YAML;

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $dockerCompose,
    ]);

    applicationParser($application);

    $parsed = Yaml::parse($application->fresh()->docker_compose);

    // All services should retain the custom-net network definition
    expect(array_key_exists('custom-net', $parsed['services']['app-list']['networks']))->toBeTrue()
        ->and(array_key_exists('custom-net', $parsed['services']['app-null']['networks']))->toBeTrue()
        ->and(array_key_exists('custom-net', $parsed['services']['app-bare']['networks']))->toBeTrue()
        ->and(array_key_exists('custom-net', $parsed['services']['app-empty-map']['networks']))->toBeTrue()
        ->and(array_key_exists('custom-net', $parsed['services']['app-map-config']['networks']))->toBeTrue();
});

test('serviceParser preserves networks defined in map form with null or bare key', function () {
    $dockerCompose = <<<'YAML'
services:
  srv-list:
    image: alpine:3.24
    networks:
      - custom-net
  srv-null:
    image: alpine:3.24
    networks:
      custom-net: null
  srv-bare:
    image: alpine:3.24
    networks:
      custom-net:
  srv-empty-map:
    image: alpine:3.24
    networks:
      custom-net: {}
  srv-map-config:
    image: alpine:3.24
    networks:
      custom-net:
        aliases:
          - my-srv-alias
networks:
  custom-net:
    external: true
YAML;

    $service = Service::factory()->create([
        'docker_compose_raw' => $dockerCompose,
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    serviceParser($service);

    $parsed = Yaml::parse($service->fresh()->docker_compose);

    expect(array_key_exists('custom-net', $parsed['services']['srv-list']['networks']))->toBeTrue()
        ->and(array_key_exists('custom-net', $parsed['services']['srv-null']['networks']))->toBeTrue()
        ->and(array_key_exists('custom-net', $parsed['services']['srv-bare']['networks']))->toBeTrue()
        ->and(array_key_exists('custom-net', $parsed['services']['srv-empty-map']['networks']))->toBeTrue()
        ->and(array_key_exists('custom-net', $parsed['services']['srv-map-config']['networks']))->toBeTrue();
});
