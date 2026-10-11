<?php

namespace RadarData\Laravel;

use Illuminate\Support\ServiceProvider;
use RadarData\Configuracao;
use RadarData\RadarData;

class RadarDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/radardata.php', 'radardata');

        // Singleton: o cliente HTTP por baixo mantem conexao, e criar um por
        // chamada joga isso fora.
        $this->app->singleton(RadarData::class, function ($app) {
            $c = $app['config']['radardata'];

            return new RadarData(new Configuracao(
                token: (string) ($c['token'] ?? ''),
                base: (string) ($c['base'] ?? 'https://api.radardata.com.br/api/v2'),
                timeout: (float) ($c['timeout'] ?? 30),
                tentativas: (int) ($c['tentativas'] ?? 3),
            ));
        });

        $this->app->alias(RadarData::class, 'radardata');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/radardata.php' => config_path('radardata.php'),
            ], 'radardata-config');
        }
    }
}
