# RadarData SDK para PHP

SDK oficial do [RadarData](https://radardata.com.br) para PHP 8.2+, com
integração pronta para Laravel. Embrulha a API pública (`/api/v2`): consultas,
Verify (verificação de identidade), Motor de Análises e webhooks.

A API continua disponível para quem preferir chamá-la direto. Este pacote
existe para você não precisar.

```bash
composer require radardata/sdk-php
```

## Começando

```php
use RadarData\RadarData;

$radar = new RadarData('seu-token-de-api');

$saldo = $radar->catalogo()->saldo();
echo $saldo->emReais();            // 1.234,56
echo $saldo->disponivelCentavos;   // 100000
```

O token é o **token de API**, criado no painel, e não o da sua sessão do
painel. Ele carrega escopos (`consultas`, `verify`, `analises`, `leitura`,
`webhooks`): uma chamada fora do escopo volta `SemEscopo`.

## Laravel

O pacote se registra sozinho. Publique a config se quiser editá-la:

```bash
php artisan vendor:publish --tag=radardata-config
```

```dotenv
RADARDATA_TOKEN=seu-token
RADARDATA_WEBHOOK_SECRET=o-segredo-que-assina-seus-webhooks
# Só se você usa Verify solto, sem análise: cada fluxo tem o seu segredo.
RADARDATA_VERIFY_WEBHOOK_SECRET=o-segredo-do-fluxo
```

```php
use RadarData\Laravel\RadarDataFacade as RadarData;

$sessao = RadarData::verify()->criarSessao('kyc-cnpj', ['cnpj' => '11222333000181']);
```

## Seus proprios fluxos de analise

Da para montar o pipeline por codigo, em vez de desenha-lo no painel: voce
manda a definicao inteira num array e recebe um fluxo publicado, pronto para
rodar com `analises()->criar()`.

```php
$definicao = [
    'entrada' => ['placa' => ['tipo' => 'texto', 'obrigatorio' => true]],
    'etapas' => [
        ['chave' => 'nacional', 'tipo' => 'consulta', 'servico' => 'consulta-nacional',
         'documento' => '{{entrada.placa}}', 'opcional' => true, 'paralelo' => true],
    ],
    'regras' => [],
    'saidas' => [['chave' => 'nacional', 'de' => 'nacional']],
];

// Quanto cada analise vai custar, sem gravar nada.
echo $radar->fluxos()->validar($definicao)['preco'];

$radar->fluxos()->publicar('laudo', 'Laudo', 'VEICULO', $definicao);

// E a partir daqui e um produto como outro qualquer.
$radar->analises()->criar('laudo', ['placa' => 'ABC1D23']);
```

Duas coisas que valem saber antes:

- **O preco nao vai no pedido.** Ele e calculado pelas bases que o fluxo
  consulta, com a tabela da sua conta, e cobra o caminho mais caro. Chame
  `validar()` antes de publicar para ver o numero.
- **Escopo `fluxos`, separado de `analises`.** Publicar fluxo escolhe o que e
  consultado e que regra reprova alguem. Use um token de provisionamento aqui,
  e deixe o token do dia a dia so com `analises`.

Publicar e idempotente pelo conteudo: mandar a mesma definicao de novo nao cria
versao nova, entao da para chamar isto a cada deploy.

## Consultas

```php
$resultado = $radar->consultas()->executar('cpf', '12345678909');
```

Gasta saldo. O retorno vem como array porque cada serviço devolve um corpo
diferente e a lista cresce: tipar tudo aqui faria o SDK desatualizar a cada
serviço novo sem ninguém perceber.

Consulta demorada responde que está em andamento e avisa pelo webhook
`consulta.concluida`. O resultado sai pelo `consulta_id`:

```php
$r = $radar->consultas()->resultado($consultaId);
// $r['meta']['status']: em_andamento | concluida | erro | expirada; o dado em $r['data']
```

Erro terminal chega aqui como resposta, em `$r['error']`, e não como exceção.

Para saber o que a **sua** conta pode consultar, e por quanto:

```php
foreach ($radar->catalogo()->servicos() as $s) {
    echo "{$s->slug}: {$s->nome} ({$s->precoCentavos} centavos)\n";
}
```

## Verify

```php
$sessao = $radar->verify()->criarSessao(
    fluxo: 'kyc-cnpj',                      // uuid do fluxo ou o alias dele
    input: ['cnpj' => '11222333000181'],
    opcoes: ['reference_id' => 'pedido-4471', 'redirect_url' => 'https://seusite.com/ok'],
);

// Mande a pessoa para cá:
echo $sessao->url;
```

O resultado chega por **webhook**. Dá para consultar também:

```php
$sessao = $radar->verify()->sessao($sessao->id);

if ($sessao->concluida() && $sessao->aprovada()) {
    echo $sessao->valorCentavos;   // quanto a sessão custou para você
}
```

`custoCentavos` continua existindo com o mesmo número, mas está depreciado: use
`valorCentavos`.

> **Criar sessão não é idempotente.** Se der timeout, **não chame de novo às
> cegas**: você pagaria duas sessões. Mande um `reference_id` seu e procure por
> ele antes de criar outra.

## Análises

Assíncrono: criar devolve o id, o resultado chega por webhook.

```php
$analise = $radar->analises()->criar(
    'kyc-pf',
    ['cpf' => '12345678909'],
    chaveIdempotencia: 'cadastro-90321',   // um id seu: repetir não cobra de novo
);
$estado = $radar->analises()->mostrar($analise['id']);
```

### Documentos

Produtos que leem documentos recebem o arquivo **antes** da análise. Você envia,
guarda o `id` e manda na entrada:

```php
$identidade = $radar->documentos()->enviar('/tmp/rg.jpg', 'identidade');
$contrato   = $radar->documentos()->enviar($recursoAberto, 'contrato_social', 'contrato.pdf');

$radar->analises()->criar('abertura-de-conta', [
    'cpf' => '52998224725',
    'cnpj' => '55016784000138',
    'documentos' => [
        ['id' => $identidade['id'], 'tipo' => 'identidade'],
        ['id' => $contrato['id'], 'tipo' => 'contrato_social'],
    ],
]);
```

JPEG, PNG ou PDF, até 10 MB. Os tipos estão em `Documentos::TIPOS`. Documento
que não entra em análise nenhuma expira em 24 horas. O envio **não se repete
sozinho**: a API não tem idempotência ali.

### Quando a decisão é sua

Quando a análise para esperando o seu time (`pendencia.tipo` = `manual` no
evento `analise.pendente`), responda:

```php
$r = $radar->analises()->decidir(
    $id,
    'aprovar',                          // ou 'recusar', que exige o motivo
    motivo: 'Confirmado com o titular.',
    responsavel: 'ana@sualoja.com.br',  // alguns produtos exigem
);

// Se o próximo passo é a prova de vida, o link já vem aqui:
$link = $r['pendencia']['url'] ?? null;
```

> Até a 0.3.0 este método mandava `decisao` e a API recusava toda chamada com
> 422. A partir da 0.4.0 manda `acao`, que é o contrato da API.

## Mensagens (SMS)

Escopo `mensagens`, que **não vem ligado por padrão**: marque ao criar o token.
Cobra por mensagem aceita; o que falha volta para o saldo.

```php
$r = $radar->mensagens()->enviar(
    '+5511999999999',
    'Seu código de acesso é 482913.',
    'pedido-48213-codigo',   // repetir com a mesma chave não manda de novo
);

$r['mensagem']['status'];   // "aceita": saiu para a operadora
$r['meta']['saldo'];        // em centavos
```

- Até 160 caracteres. **Acento sai** antes do envio e **emoji recusa** a
  mensagem (`EntradaInvalida`, `codigo` = `texto_invalido`), sem cobrar.
- `aceita` não é entrega confirmada: para código de acesso, ofereça reenviar.
- `envio_incerto` (`ErroDoServidor`, 504): não confirmamos se saiu e não foi
  cobrado. Reenviar com chave nova pode fazer a pessoa receber duas.
- `limite_destino` e `limite_diario` (`MuitasTentativas`): 5 por número por
  hora e 1.000 por conta por dia.
- O aviso chega pelos webhooks `mensagem.aceita` e `mensagem.falhou`.
- **A resposta se perdeu?** O erro traz a chave usada em `$e->chaveIdempotencia`.
  Repita com **ela**: `Conflito` (`idempotency_em_processamento`) quer dizer que
  a primeira ainda está saindo; espere e repita com a mesma chave. Chave nova
  pode fazer a pessoa receber duas. Para código de acesso, passe sempre uma
  chave sua.

```php
$pagina = $radar->mensagens()->listar(1, 25);  // sem o texto
$uma = $radar->mensagens()->obter($id);        // com o texto, por 30 dias
```

## Eventos

O seu sistema conta o que aconteceu e a configuração do lado da RadarData
decide que análises acordam. Evento sem gatilho configurado não é erro.

```php
$r = $radar->eventos()->enviar('pedido.criado', 'pedido-'.$pedido->id, ['valor' => 15000],
    segredo: env('RADARDATA_EVENTOS_SECRET'),   // quando a origem exige assinatura
);
```

## Operações (COAF)

Para factoring: cada operação registrada é conferida, e a que precisa de olhar
humano volta com `revisar`.

```php
$op = $radar->operacoes()->registrar(
    ['cliente_documento' => '11222333000181', 'sacado_documento' => '52998224725', 'valor' => 150000],
    'operacao-'.$titulo,   // repetir não cobra de novo
);
$pendentes = $radar->operacoes()->listar(revisar: true);
$radar->operacoes()->decidir($op['id'], 'ARQUIVAR', 'Operação conferida com o cliente.');
```

## Monitoramentos judiciais

Cobrança adiantada a cada 30 dias. A novidade chega pelo webhook
`monitoramento_judicial.evento`, com o conteúdo, que some da RadarData em 30 dias.

```php
$m = $radar->monitoramentosJudiciais()->criar('monitoramento-documento', ['documento' => '12345678909', 'nome' => 'Fornecedor principal']);
$eventos = $radar->monitoramentosJudiciais()->eventos($m['id']); // ['data' => [...], 'meta' => ['paginas' => ...]]
$radar->monitoramentosJudiciais()->cancelar($m['id']); // vale até o fim do período pago
```

No catálogo, `$servico->monitoramento` diz quais serviços se usam assim, e
`$servico->rota`, onde.

## Webhooks: conferir que o aviso é nosso

```php
use RadarData\Webhooks\Assinatura;

Route::post('/radardata/webhook', function (Request $request) {
    $evento = Assinatura::exigir(
        $request->getContent(),                              // o corpo CRU
        $request->header('X-RadarData-Signature'),
        config('radardata.webhook_secret'),
    );

    // Deduplique: reenviamos em caso de falha.
    $entrega = $request->header('X-RadarData-Delivery');

    return response()->noContent();
});
```

Três coisas que costumam dar errado aqui, e valem a leitura:

**Use o corpo cru.** `$request->getContent()`, não o array decodificado.
Reserializar muda espaços e ordem das chaves, o HMAC muda junto e a conferência
falha sem motivo aparente. É o erro mais comum de qualquer integração deste
tipo, e há um teste neste repositório que o demonstra.

**Deduplique pelo `X-RadarData-Delivery`.** Reenviamos quando a entrega falha,
então o mesmo evento pode chegar duas vezes.

**A assinatura prova origem e conteúdo, não a hora.** O timestamp e o id de
entrega viajam em cabeçalho, fora do corpo assinado, então ela não protege
contra o reenvio de um aviso antigo e autêntico por quem o tenha interceptado.
A dedup acima é a defesa prática.

## Erros

Cada situação tem a sua classe, para você não comparar strings:

| Classe | Quando |
|---|---|
| `NaoAutenticado` | 401, token ausente, inválido ou revogado |
| `SaldoInsuficiente` | 402, a conta não tem saldo para a operação |
| `SemEscopo` | 403, o token não tem o escopo daquela rota |
| `NaoEncontrado` | 404 |
| `Conflito` | 409, a mesma chave ainda em andamento ou usada em outro pedido |
| `EntradaInvalida` | 422, veja `->corpo` para os campos |
| `MuitasTentativas` | 429 |
| `ErroDoServidor` | 5xx, falha nossa |
| `FalhaDeRede` | **sem resposta**: timeout, DNS, conexão recusada |

Todas estendem `ErroRadarData`, que traz `->codigo`, `->status` e `->corpo`.

`FalhaDeRede` é separado de propósito: ali o SDK **não sabe** se a operação
aconteceu. Numa chamada que gasta saldo isso importa, e o caminho é consultar
pela mesma chave de idempotência, não repetir às cegas.

## Repetição automática, e por que ela não é global

O SDK repete em 429 e 5xx **somente** nas chamadas em que manda
`Idempotency-Key`: consultas, análises, mensagens, eventos e operações. A mesma
chave faz a API devolver a resposta anterior em vez de cobrar de novo. Não
repete o que a mesma chave devolveria igual (`limite_destino`, `limite_diario`,
`teto_excedido`, `envio_incerto`, `envio_recusado`), e todo erro traz a chave
usada em `$e->chaveIdempotencia`.

Criar sessão de Verify **não** tem idempotência na API, então o SDK **não
repete** ali, qualquer que seja a configuração. Repetir criaria uma segunda
sessão faturável, e um SDK que cobra duas vezes do seu cliente é pior do que
um SDK que devolve erro.

A chave é gerada automaticamente quando você não passa uma. Guarde a sua se
quiser poder reconsultar o resultado depois de um timeout:

```php
$chave = \RadarData\Recursos\Consultas::chave();
$radar->consultas()->executar('cpf', '12345678909', chaveIdempotencia: $chave);
```

## Apontando para outro ambiente

```php
use RadarData\{Configuracao, RadarData};

$radar = new RadarData(new Configuracao(
    token: 'seu-token',
    base: 'https://api.radardata.dev.local/api/v2',
    timeout: 10,
));
```

## Desenvolvimento

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint

# contrato contra uma api de verdade, com token de SANDBOX
RADARDATA_CONTRATO_BASE=http://127.0.0.1:8000/api/v2 RADARDATA_CONTRATO_TOKEN=... vendor/bin/phpunit tests/ContratoTest.php
```

## Licença

MIT.
