# Briefing do sistema — para desenvolver o CRM

> Cole este documento no início de uma conversa para dar contexto completo do
> sistema atual. Objetivo da próxima etapa: **construir um CRM** integrado.

## O que é
Marketplace + painel administrativo de uma **loja de motos seminovas** (Adventure Motos).
As motos são anunciadas com fotos/ficha/preço; o contato do cliente é via WhatsApp.
Já existe registro de vendas com dados do cliente. O CRM deve ser construído **em cima
dessa base** (não recriar do zero).

## Stack e regras (NÃO mudar de tecnologia)
- **PHP puro + MySQL/MariaDB via PDO**. Sem framework, sem Composer, sem build.
- Front: HTML + CSS próprio (`assets/style.css`) + JS vanilla. Fontes: Big Shoulders
  Display (títulos), Inter (corpo), JetBrains Mono (dados).
- Hospedagem **Hostinger**, deploy automático por **Git** (`git push` → atualiza `public_html`).
- Todo arquivo `.php` inclui `config.php` (conexão PDO em `$pdo` + helpers).

### Convenções importantes (seguir sempre)
1. **Credenciais** ficam em `config.local.php` (fora do Git). Nunca hardcode senha.
2. **Auto-migração do banco**: em vez de pedir ALTER manual, o código cria colunas/
   tabelas que faltam via funções `ensure_*($pdo)` (checam `information_schema` e rodam
   `ALTER/CREATE` dentro de try/catch, uma vez por request). Ver `inc/moto_fields.php`.
   → **O CRM deve usar o mesmo padrão** (ex.: `ensure_crm_schema($pdo)`).
3. **Cache-buster do CSS**: `assets/style.css?v=NNNN` está em 3 arquivos
   (`login.php`, `register.php`, `inc/header.php`). Ao mudar o CSS, subir o número nos 3.
4. **URLs**: usar sempre `base_url('caminho')` (função em config.php) — funciona em
   subpasta ou domínio raiz.
5. **Configurações** ficam na tabela `settings` (chave/valor). Helpers: `setting_get`,
   `setting_set`, `setting_get_any`.
6. Escape de saída com `htmlspecialchars`; queries sempre com prepared statements.

## Autenticação e permissões (`inc/auth.php`)
- Tabela `users`: `role` = `gerente` | `vendedor`, e flags `can_create/can_edit/can_delete`.
- `require_login()`, `require_role('gerente')`, `user_can('edit'|'create'|'delete'|...)`,
  `current_user()`. Painel fica em `/painel/*` e usa esses guards.

## Estrutura de arquivos
```
index.php        vitrine (grid de motos, filtros, cards com WhatsApp)
moto.php         página de detalhe da moto
login/logout/register.php
config.php       conexão + base_url + settings globais
config.local.php credenciais (fora do Git)
inc/header.php   topo + faixa de confiança (público) / nav (painel)
inc/footer.php   rodapé
inc/auth.php     login/sessão/permissões
inc/moto_fields.php  helpers da ficha + ensure_moto_schema() + ensure_vendas_schema()
painel/dashboard.php   visão geral + gráfico de vendas
painel/motos.php       lista de motos + ações (vender, reservar, cancelar venda)
painel/moto_form.php   cadastro/edição de moto (+ cropper 4:3 de fotos)
painel/moto_mark_sold.php  registrar venda (captura dados do cliente)
painel/moto_unsell.php     cancelar venda (exige senha de gerente)
painel/users.php       usuários e permissões (modal de novo usuário)
painel/config.php      configurações da loja (nome, whatsapp, logo, banner, faixa)
painel/analytics.php   relatórios de acesso (page_events / active_sessions)
```

## Banco de dados (tabelas existentes)
- **users** (id, nome, email, senha_hash, role, can_create/edit/delete, created_at)
- **motos** (id, titulo, modelo=marca, ano_modelo, quilometragem, condicao,
  cor, valor, valor_a_combinar, valor_fipe, descricao, + ficha técnica,
  status[disponivel|reservada|vendida], sold_at, created_by, created_at, updated_at)
- **moto_fotos** (id, moto_id, caminho, is_cover, ordem) — ON DELETE CASCADE
- **vendas** (id, moto_id, vendedor_id, vendedor_nome, cliente_nome,
  cliente_telefone, cliente_email, cliente_doc, valor_venda, data_venda,
  observacao, created_at)  ← **base para o CRM (clientes/histórico)**
- **settings** (key, value) — config chave/valor
- **opcoes_moto** (tags reutilizáveis), **motos_padroes** (presets de ficha)
- **page_events / active_sessions** (analytics de acesso)

Schema completo do zero: `criar_banco_novo.sql`. Documentação: `DOCUMENTACAO.md`.

## Fluxo de contato (importante para o CRM)
Hoje o cliente clica em **"Chamar no zap"** nos cards / página da moto e vai direto pro
WhatsApp com mensagem pré-preenchida (ex.: "Oi! Tenho interesse na CG 160 TITAN 2024 por
R$ 19.900"). O clique é rastreado só via analytics (`page_events`, atributos `data-wa`
e `data-moto-id` nos botões). **O lead em si não é capturado hoje** — isso é uma
oportunidade do CRM.

## Objetivo do CRM (a definir/refinar)
Ideias iniciais (validar prioridades):
1. **Leads** — capturar contatos que chamam no WhatsApp (ex.: mini-form antes de abrir o
   zap, ou registro manual) com origem, moto de interesse e vendedor.
2. **Clientes** — lista consolidada (a partir de `vendas`) com histórico de compras.
3. **Funil/pipeline** — etapas (Novo → Em negociação → Fechado/Perdido) por negociação.
4. **Atribuição por vendedor** e **relatórios** (conversão, vendas por vendedor).

### Sugestão de modelagem (novas tabelas — criar via ensure_crm_schema)
- `crm_leads` (id, nome, telefone, email, moto_id NULL, origem, vendedor_id NULL,
  etapa[novo|contato|negociacao|fechado|perdido], observacao, created_at, updated_at)
- `crm_interacoes` (id, lead_id, tipo, texto, user_id, created_at) — histórico de contatos
- (clientes podem ser derivados de `vendas` + `crm_leads`, sem tabela nova a princípio)

## Pedido ao assistente
Ao ajudar a construir o CRM: manter PHP+MySQL, seguir os padrões acima
(auto-migração `ensure_*`, `base_url`, prepared statements, guards de permissão,
cache-buster ao mexer no CSS), integrar ao painel existente (`/painel/`) e reaproveitar
os dados de `vendas`. Entregar em arquivos completos, prontos para `git push`.
