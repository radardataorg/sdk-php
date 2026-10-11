<?php

namespace RadarData\Recursos;

use RadarData\Transporte;

/**
 * Para onde mandamos os eventos, e o segredo que os assina.
 *
 * Escopo `webhooks`, que e proposital e separado: de a um token de
 * PROVISIONAMENTO, nao ao token que o seu servidor usa todo dia. Quem tem
 * este escopo pode redirecionar os seus eventos.
 */
class Webhooks
{
    public function __construct(private readonly Transporte $t) {}

    /** @return array<string, mixed> Destino atual e o PREFIXO do segredo, nunca o segredo. */
    public function atual(): array
    {
        return $this->t->pedir('GET', 'webhooks');
    }

    /** @return array<string, mixed> */
    public function definir(string $url, array $eventos = []): array
    {
        return $this->t->pedir('PUT', 'webhooks', array_filter([
            'url' => $url,
            'eventos' => $eventos ?: null,
        ], fn ($v) => $v !== null));
    }

    /** @return array<string, mixed> */
    public function remover(): array
    {
        return $this->t->pedir('DELETE', 'webhooks');
    }

    /**
     * Gira o segredo e devolve o novo, UMA unica vez.
     *
     * Guarde na hora: a consulta nunca mais mostra o valor inteiro. E lembre
     * que girar invalida a assinatura dos avisos em voo.
     *
     * @return array<string, mixed>
     */
    public function girarSegredo(): array
    {
        return $this->t->pedir('POST', 'webhooks/secret/rotate');
    }

    /** @return array<string, mixed> Dispara um evento de teste para o seu endpoint. */
    public function testar(?string $url = null): array
    {
        return $this->t->pedir('POST', 'webhooks/testar', $url ? ['url' => $url] : null);
    }
}
