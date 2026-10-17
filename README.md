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
    // ...
}
```

> **Criar sessão não é idempotente.** Se der timeout, **não chame de novo às
> cegas**: você pagaria duas sessões. Mande um `reference_id` seu e procure por
> ele antes de criar outra.

## Análises

Assíncrono: criar devolve o id, o resultado chega por webhook.

```php
$analise = $radar->analises()->criar('kyc-pf', ['cpf' => '12345678909']);
$estado  = $radar->analises()->mostrar($analise['id']);
```

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
`Idempotency-Key`: consultas, análises e operações. A mesma chave faz a API
devolver a resposta anterior em vez de cobrar de novo.

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
```

## Licença

MIT.
