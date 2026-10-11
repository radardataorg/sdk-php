<?php

namespace RadarData\Webhooks;

/**
 * Confere que o webhook veio mesmo do RadarData.
 *
 * Mandamos `X-RadarData-Signature: sha256=<hmac do CORPO CRU com o seu
 * webhook_secret>`, mais `X-RadarData-Event`, `X-RadarData-Delivery` e
 * `X-RadarData-Timestamp`.
 *
 * O CORPO TEM DE SER O CRU. Em Laravel e `$request->getContent()`, nao o
 * array ja decodificado: reserializar muda espaco e ordem de chave, o HMAC
 * muda junto e a conferencia falha sem motivo aparente. E o erro mais comum
 * nesta parte, em qualquer integracao.
 *
 * LIMITE QUE VOCE PRECISA CONHECER: o timestamp e o id de entrega viajam em
 * CABECALHO, fora do corpo assinado, entao a assinatura prova a ORIGEM e o
 * CONTEUDO, e nao a hora. Ela nao protege contra reenvio de um aviso antigo
 * e autentico por quem o tenha interceptado. A defesa pratica e guardar o
 * `X-RadarData-Delivery` e ignorar o que ja foi processado, que voce deve
 * fazer de qualquer forma, porque reenviamos em caso de falha.
 */
class Assinatura
{
    /**
     * A assinatura confere?
     *
     * @param  string  $corpoCru  o corpo exatamente como chegou
     * @param  string|null  $assinatura  o cabecalho `X-RadarData-Signature`
     * @param  string  $segredo  o `webhook_secret` daquele fluxo ou da conta
     */
    public static function confere(string $corpoCru, ?string $assinatura, string $segredo): bool
    {
        if ($assinatura === null || $assinatura === '' || $segredo === '') {
            return false;
        }

        $esperada = 'sha256='.hash_hmac('sha256', $corpoCru, $segredo);

        // `hash_equals` e nao `===`: comparacao normal vaza, pelo tempo que
        // leva, quantos bytes iniciais bateram, e com isso da para descobrir
        // a assinatura byte a byte.
        return hash_equals($esperada, $assinatura);
    }

    /**
     * Confere e devolve o evento decodificado, ou lança.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException quando a assinatura nao confere
     */
    public static function exigir(string $corpoCru, ?string $assinatura, string $segredo): array
    {
        if (! self::confere($corpoCru, $assinatura, $segredo)) {
            throw new \RuntimeException('Assinatura do webhook do RadarData não confere.');
        }

        $dados = json_decode($corpoCru, true);

        return is_array($dados) ? $dados : [];
    }
}
