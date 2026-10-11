<?php

namespace RadarData;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use RadarData\Erros\EntradaInvalida;
use RadarData\Erros\ErroDoServidor;
use RadarData\Erros\ErroRadarData;
use RadarData\Erros\FalhaDeRede;
use RadarData\Erros\MuitasTentativas;
use RadarData\Erros\NaoAutenticado;
use RadarData\Erros\NaoEncontrado;
use RadarData\Erros\SaldoInsuficiente;
use RadarData\Erros\SemEscopo;

/**
 * O transporte: fala HTTP com a `/api/v2` e traduz resposta em dado ou erro.
 *
 * Tres decisoes que valem o comentario, porque sao as que diferenciam um SDK
 * de um `curl` embrulhado:
 *
 * 1. RETRY SO ONDE E SEGURO. A api aceita `Idempotency-Key` em consulta,
 *    analise e operacao, e e so nessas que repetir e seguro: a mesma chave
 *    devolve a mesma resposta em vez de cobrar de novo. Criar sessao de
 *    Verify NAO tem idempotencia, entao repetir ali criaria uma segunda
 *    sessao faturavel. Por isso o retry nao e uma opcao global: ele e ligado
 *    por chamada, e so quando o SDK manda a chave.
 * 2. A CHAVE E GERADA PELO SDK quando quem chama nao passa uma. Deixar isso
 *    para o integrador significa, na pratica, ninguem usar, e e justamente o
 *    caso em que a falha dói: timeout numa consulta paga.
 * 3. FALHA SEM RESPOSTA NAO E ERRO DE SERVIDOR. `FalhaDeRede` existe porque
 *    nesse caso nao se sabe se a operacao aconteceu, e a acao correta e
 *    consultar pela chave, nao repetir as cegas.
 */
class Transporte
{
    private Client $http;

    public function __construct(private readonly Configuracao $config)
    {
        $this->http = $this->config->http ?? new Client([
            'base_uri' => rtrim($this->config->base, '/').'/',
            'timeout' => $this->config->timeout,
            'http_errors' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $corpo
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function pedir(
        string $metodo,
        string $caminho,
        ?array $corpo = null,
        array $query = [],
        ?string $chaveIdempotencia = null,
    ): array {
        $cabecalhos = [
            'Authorization' => 'Bearer '.$this->config->token,
            'Accept' => 'application/json',
            'User-Agent' => $this->config->userAgent(),
        ];

        // Repetir so e seguro com a chave: sem ela, uma consulta cobrada duas
        // vezes seria dinheiro do cliente.
        $podeRepetir = $chaveIdempotencia !== null;
        if ($podeRepetir) {
            $cabecalhos['Idempotency-Key'] = $chaveIdempotencia;
        }

        $opcoes = ['headers' => $cabecalhos];
        if ($corpo !== null) {
            $opcoes['json'] = $corpo;
        }
        if ($query !== []) {
            $opcoes['query'] = $query;
        }

        $tentativas = $podeRepetir ? $this->config->tentativas : 1;
        $ultimo = null;

        for ($i = 1; $i <= $tentativas; $i++) {
            try {
                $resposta = $this->http->request($metodo, ltrim($caminho, '/'), $opcoes);
            } catch (ConnectException|RequestException $e) {
                $ultimo = new FalhaDeRede(
                    'Não foi possível falar com o RadarData: '.$e->getMessage(),
                    codigo: 'falha_de_rede',
                    anterior: $e,
                );

                if ($i < $tentativas) {
                    $this->esperar($i);

                    continue;
                }

                throw $ultimo;
            }

            $status = $resposta->getStatusCode();

            // 429 e 5xx valem nova tentativa; o resto e resposta final.
            if (($status === 429 || $status >= 500) && $i < $tentativas) {
                $this->esperar($i, $resposta);

                continue;
            }

            return $this->interpretar($resposta);
        }

        throw $ultimo ?? new ErroRadarData('Falha desconhecida no transporte.');
    }

    /** @return array<string, mixed> */
    private function interpretar(Response $resposta): array
    {
        $status = $resposta->getStatusCode();
        $cru = (string) $resposta->getBody();
        $json = json_decode($cru, true);
        $corpo = is_array($json) ? $json : [];

        if ($status >= 200 && $status < 300) {
            // `data` quando a rota embrulha (catalogo, saldo, analises) e o
            // corpo inteiro quando nao (sessao de Verify). Devolver sempre o
            // conteudo util poupa quem chama de saber qual rota faz o que.
            return array_key_exists('data', $corpo) && is_array($corpo['data'])
                ? $corpo['data']
                : $corpo;
        }

        $codigo = $corpo['error']['code'] ?? null;
        $mensagem = $corpo['error']['message']
            ?? ($corpo['message'] ?? 'O RadarData respondeu '.$status.' sem detalhe.');

        throw match (true) {
            $status === 401 => new NaoAutenticado($mensagem, $codigo, $status, $corpo),
            $status === 402 => new SaldoInsuficiente($mensagem, $codigo, $status, $corpo),
            $status === 403 => new SemEscopo($mensagem, $codigo, $status, $corpo),
            $status === 404 => new NaoEncontrado($mensagem, $codigo, $status, $corpo),
            $status === 422 => new EntradaInvalida($mensagem, $codigo, $status, $corpo),
            $status === 429 => new MuitasTentativas($mensagem, $codigo, $status, $corpo),
            $status >= 500 => new ErroDoServidor($mensagem, $codigo, $status, $corpo),
            default => new ErroRadarData($mensagem, $codigo, $status, $corpo),
        };
    }

    /** Espera entre tentativas, respeitando `Retry-After` quando ele vem. */
    private function esperar(int $tentativa, ?Response $resposta = null): void
    {
        $segundos = (int) ($resposta?->getHeaderLine('Retry-After') ?: 0);

        if ($segundos <= 0) {
            // Recuo exponencial com um pouco de aleatorio, para muitos
            // clientes falhando juntos nao voltarem todos no mesmo instante.
            $segundos = min(8, 2 ** ($tentativa - 1));
            usleep((int) (($segundos + mt_rand(0, 250) / 1000) * 1_000_000));

            return;
        }

        sleep(min($segundos, 30));
    }
}
