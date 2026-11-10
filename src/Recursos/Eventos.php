<?php

namespace RadarData\Recursos;

use RadarData\Transporte;

/**
 * Gatilho por evento. Escopo `analises`. Seu sistema conta o que aconteceu (`pedido.criado`,
 * `cliente.atualizado`) e a configuracao do lado da RadarData decide que analises acordam.
 * Evento sem gatilho configurado nao e erro: volta com a lista de analises vazia.
 *
 * A `chave` e o seu id do evento: se ele ja acordou analise, mandar a mesma chave de novo
 * devolve `repetido: true` e nao acorda nada outra vez, por isso o SDK pode repetir em falha.
 */
class Eventos
{
    public function __construct(private readonly Transporte $t) {}

    /**
     * Quando a origem de eventos da sua conta exige assinatura, passe o `$segredo` dela: o SDK
     * assina o corpo exato que manda, em `X-RadarData-Signature`.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed> `data` (evento, chave, repetido, analises) e `avisos`
     */
    public function enviar(string $evento, string $chave, array $dados = [], ?string $segredo = null): array
    {
        // `(object)` para lista vazia sair `{}` e nao `[]`, que a api leria como lista.
        $corpoCru = json_encode(['evento' => $evento, 'chave' => $chave, 'dados' => (object) $dados], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $cabecalhos = $segredo !== null && $segredo !== ''
            ? ['X-RadarData-Signature' => 'sha256='.hash_hmac('sha256', $corpoCru, $segredo)]
            : [];

        return $this->t->pedir('POST', 'eventos', chaveIdempotencia: $chave, envelope: true, cabecalhos: $cabecalhos, corpoCru: $corpoCru);
    }
}
