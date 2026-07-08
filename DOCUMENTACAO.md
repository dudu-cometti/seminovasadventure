# Documentação — Marketplace de Motos Seminovas

Sistema de vitrine (marketplace) + painel administrativo para loja de motos seminovas.
Cada moto é anunciada com fotos, ficha técnica e preço; o contato do cliente é feito
via WhatsApp. Instância atual: **Adventure Motos** (`seminovas.comettiads.com`).

---

## 1. Stack técnica

| Item | Tecnologia |
|------|-----------|
| Linguagem | PHP puro (sem framework) |
| Banco | MySQL / MariaDB (via PDO) |
| Front | HTML + CSS próprio (`assets/style.css`) + JS vanilla |
| Hospedagem | Hostinger (deploy automático via Git) |
| Repositório | GitHub — `dudu-cometti/seminovasadventure` |

Não há build/compilação: o PHP roda direto no servidor. `git push` → a Hostinger
atualiza o site sozinho (webhook de auto-deploy).

---

## 2. Estrutura de arquivos

```
/                     raiz = site público
├── index.php         vitrine (home, lista de motos, filtros, banner)
├── moto.php          página de uma moto (galeria, ficha, WhatsApp)
├── login.php         login do painel
├── logout.php
├── register.php      cadastro público (1º usuário vira gerente)
├── track.php         registra eventos de analytics
├── config.php        conexão do banco + helpers globais (base_url, etc.)
├── config.local.php  CREDENCIAIS DO BANCO (fora do Git!)
├── config.local.example.php  modelo das credenciais
├── assets/           style.css, track.js
├── uploads/          fotos das motos, logo, banners (não versionado)
├── inc/
│   ├── header.php    topo + navegação (auto-oculta busca no scroll)
│   ├── footer.php    rodapé (link discreto "Acesso restrito")
│   ├── auth.php      login/sessão/permissões
│   └── moto_fields.php  helpers da ficha + AUTO-MIGRAÇÕES do banco
└── painel/           área logada (admin)
    ├── dashboard.php     visão geral e gráfico de vendas
    ├── motos.php         lista de motos + ações (vender, reservar…)
    ├── moto_form.php     cadastrar/editar moto + fotos
    ├── moto_mark_sold.php registrar venda (dados do cliente)
    ├── moto_unsell.php    cancelar venda (exige senha de gerente)
    ├── moto_delete.php / moto_foto_delete.php
    ├── moto_set_cover.php / moto_foto_reorder.php  ordenar fotos
    ├── moto_toggle_reserva.php
    ├── users.php          usuários e permissões (+ modal novo usuário)
    ├── config.php         configurações da loja (nome, whats, logo, banner…)
    ├── padroes.php        presets de ficha
    ├── analytics.php      relatórios de acesso
    └── placa_lookup.php   consulta placa (API externa)
```

---

## 3. Deploy (fluxo tipo Vercel)

1. Editar o código localmente.
2. `git add -A && git commit -m "..."` e `git push`.
3. A Hostinger recebe o webhook e atualiza `public_html` automaticamente.
4. Conferir em **hPanel → Avançado → GIT → Implantações** (status "Concluído").

> ⚠️ **Rede bloqueando SSH porta 22?** O Git está configurado para usar
> `ssh.github.com:443`. Se clonar em outra máquina, rode:
> `git config --global url."ssh://git@ssh.github.com:443/".insteadOf "git@github.com:"`

### Regra do cache do CSS (IMPORTANTE)
O `style.css` é carregado com um número de versão: `assets/style.css?v=NNNN`,
que aparece em **3 arquivos**: `login.php`, `register.php`, `inc/header.php`.
**Toda vez que mexer no `style.css`, aumente esse número nos 3** (ex.: 2008 → 2009),
senão o navegador/servidor continua servindo o CSS antigo do cache.
(Mudanças em `<style>` inline dentro dos `.php` não precisam disso.)

---

## 4. Credenciais do banco

Ficam em **`config.local.php`** (NÃO vai para o Git — está no `.gitignore`).
Modelo em `config.local.example.php`:

```php
<?php
$DB_HOST = 'localhost';
$DB_NAME = 'nome_do_banco';
$DB_USER = 'usuario_do_banco';
$DB_PASS = 'senha_do_banco';
```

Em cada servidor/instância, esse arquivo é criado **manualmente** (o deploy não o traz).

---

## 5. Funcionalidades

### Vitrine pública
- **Banner configurável** na abertura (imagem desktop + celular) ou texto padrão.
- Busca por texto, filtro por marca, ano e ordenação (recentes / preço / km).
- Cards com galeria de fotos, favoritar (localStorage), badge de status.
- **Fotos sem corte**: `object-fit: contain` + fundo desfocado da própria foto.
- Preço normal **ou "Valor a combinar"** (quando a moto é sob consulta).
- Contato direto no WhatsApp com mensagem pré-montada.
- Barra de busca some ao rolar para baixo e volta ao subir.

### Painel administrativo
- **Motos**: cadastrar/editar com ficha completa (procedência, conservação,
  pneus, mecânica, diferenciais, condições comerciais), preenchimento por placa,
  presets ("padrões") e busca FIPE.
- **Fotos**: até 5 por moto, reordenáveis (setas), 1ª foto = capa.
- **Preço opcional**: pode salvar sem valor marcando "Valor a combinar".
- **Vendas**: registra valor, data, vendedor (lista de usuários) e dados do
  cliente (nome, telefone, e-mail, CPF, observação).
- **Cancelar venda**: reverte a venda; exige **e-mail + senha de um gerente**.
- **Usuários**: criar (modal), definir função (gerente/vendedor) e permissões
  (cadastrar/editar/excluir). Gerente tem acesso total.
- **Dashboard/Analytics**: estoque, vendas do período, gráfico e eventos de acesso.

---

## 6. Configurações (tabela `settings`)

Chaves usadas (editáveis em **Painel → Configurações**):

| Chave | O que é |
|-------|---------|
| `marketplace_nome`   | Nome da loja (topbar, rodapé) |
| `marketplace_cidade` | Cidade / UF |
| `whatsapp_number`    | WhatsApp com DDI+DDD, só dígitos (ex: 5527999999999) |
| `logo_path`          | Caminho da logo (ex: `uploads/logo_123.png`) |
| `banner_ativo`       | `1` usa banner na home, `0` usa o texto padrão |
| `banner_desktop`     | Caminho do banner desktop (~1200×400) |
| `banner_mobile`      | Caminho do banner celular (~1080×1080) |
| `placa_api_token`    | Token da API de consulta de placa (opcional) |

---

## 7. Banco de dados (tabelas)

Estrutura completa no arquivo **`criar_banco_novo.sql`** (roda tudo do zero).
Resumo das tabelas:

- **users** — logins. `role` (gerente/vendedor) + permissões `can_create/edit/delete`.
- **motos** — anúncios. Campos-chave: `titulo, modelo, ano_modelo, quilometragem,
  cor, valor, valor_a_combinar, valor_fipe, descricao`, ficha (procedência,
  conservação, pneus, mecânica, diferencial, comerciais), `status`
  (disponivel/reservada/vendida), `sold_at`, `created_by`, `created_at`, `updated_at`.
- **moto_fotos** — fotos (`caminho`, `is_cover`, `ordem`). `ordem` define a
  sequência; a de `ordem` 0 é a capa. ON DELETE CASCADE com motos.
- **vendas** — registro de cada venda: `moto_id, vendedor_id, vendedor_nome,
  cliente_nome, cliente_telefone, cliente_email, cliente_doc, valor_venda,
  data_venda, observacao, created_at`.
- **settings** — configurações (chave/valor). Ver seção 6.
- **config** — tabela legada (compatibilidade).
- **opcoes_moto** — tags reutilizáveis (ex.: diferenciais).
- **motos_padroes** — presets de ficha (JSON).
- **page_events / active_sessions** — analytics (eventos e quem está online).

### Auto-migração (importante)
O arquivo `inc/moto_fields.php` tem funções que **criam colunas/tabelas que
faltarem, automaticamente**, na primeira vez que uma tela é aberta:
- `ensure_moto_schema()` → `moto_fotos.ordem`, `motos.valor_a_combinar`.
- `ensure_vendas_schema()` → tabela `vendas` (com todas as colunas) e `motos.sold_at`.

Ou seja: mesmo um banco criado por uma versão antiga do `.sql` se ajusta sozinho.
Ainda assim, o `criar_banco_novo.sql` já está atualizado com tudo.

---

## 8. Como DUPLICAR o sistema para outra empresa

Passo a passo para uma nova loja (ex.: "Loja XYZ"):

1. **Novo repositório (opcional):** pode usar o mesmo código; recomenda-se um
   repositório/branch por cliente, ou um fork.

2. **Banco de dados:**
   - Na Hostinger da nova empresa, crie um banco MySQL novo.
   - Abra o phpMyAdmin, selecione o banco e rode **todo** o `criar_banco_novo.sql`.

3. **Credenciais:**
   - No servidor novo, crie `config.local.php` (copie do `config.local.example.php`)
     com host/nome/usuário/senha do banco novo.

4. **Deploy:**
   - Conecte o repositório no **hPanel → GIT** apontando para `public_html`.
   - Ative o Auto-Deployment e cole o webhook no GitHub (Settings → Webhooks).

5. **Primeiro acesso:**
   - Acesse `.../register.php` e crie a **primeira conta** — ela vira **gerente**
     automaticamente. Depois crie os demais usuários pelo painel (Usuários).

6. **Personalização (Painel → Configurações):**
   - Nome da loja, cidade, WhatsApp.
   - Logo e banner (desktop + celular).
   - Token da API de placas, se for usar.

7. **Cadastrar as motos** e pronto.

> Cada instância é **independente**: banco, uploads e configurações próprios.
> Só o código-fonte é compartilhado.

---

## 9. Contatos / referências

- Repositório: https://github.com/dudu-cometti/seminovasadventure
- Site (Adventure): https://seminovas.comettiads.com
- API de placas: wdapi2.com.br / apiplacas (token em Configurações)
