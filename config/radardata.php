<?php

return [
    /*
     * Token de API da sua conta, criado no painel do RadarData.
     *
     * Vai no `.env`, nunca aqui: este arquivo e publicado e versionado no seu
     * projeto. Use um token POR AMBIENTE, para poder revogar o de
     * desenvolvimento sem derrubar producao.
     */
    'token' => env('RADARDATA_TOKEN'),

    'base' => env('RADARDATA_BASE', 'https://api.radardata.com.br/api/v2'),

    'timeout' => (float) env('RADARDATA_TIMEOUT', 30),

    /*
     * Tentativas em chamada idempotente. Vale so onde o SDK manda
     * `Idempotency-Key` (consulta, analise, operacao). Criar sessao de Verify
     * nao tem idempotencia na api, entao o SDK NAO repete ali, qualquer que
     * seja este numero: repetir criaria uma segunda sessao faturavel.
     */
    'tentativas' => (int) env('RADARDATA_TENTATIVAS', 3),

    /*
     * Segredo que assina os webhooks que te mandamos. E o da conta, que assina
     * os eventos de analise, e voce ve em Painel > Webhooks.
     */
    'webhook_secret' => env('RADARDATA_WEBHOOK_SECRET'),

    /*
     * Segredo do webhook de um fluxo de Verify usado solto, sem analise em
     * volta. Cada fluxo tem o seu, diferente do da conta: conferir um evento
     * de sessao com `webhook_secret` falha sempre.
     */
    'verify_webhook_secret' => env('RADARDATA_VERIFY_WEBHOOK_SECRET'),
];
