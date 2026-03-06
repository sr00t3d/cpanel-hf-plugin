# Plugin High Forensic para cPanel (`hforensic`)

Leia-me: [BR](README.md)

![Licença](https://img.shields.io/github/license/sr00t3d/cpanel-hf-plugin) ![Script Shell](https://img.shields.io/badge/shell-script-green) ![Script PHP](https://img.shields.io/badge/php-script-green)

<img width="700" src="cpanel-hf-plugin-cover.webp" />

High Forensic é um plugin para cPanel voltado à perícia de arquivos em nível de conta. Ele permite que um usuário do cPanel inspecione arquivos dentro do seu próprio diretório home, execute análises focadas em evidências usando `hf.sh`, atualize logs web da conta com segurança e coloque arquivos suspeitos em quarentena ou os restaure.

Este repositório contém o pacote completo do plugin usado por  
`/usr/local/cpanel/scripts/install_plugin`.

## Visão geral

High Forensic foi projetado para ambientes de hospedagem compartilhada onde o usuário do cPanel **não deve receber acesso privilegiado ao host**. O plugin utiliza um modelo estritamente limitado à conta:

- Navegação de arquivos limitada a `/home/<cpanel_user>`.
- Auditorias executadas em modo não privilegiado (`hf.sh --mode=user`).
- Atualização de logs delegada por meio de um wrapper sudo restrito.
- Operações de quarentena limitadas à área de metadados pertencente à conta.

A entrada de menu aparece em **Arquivos** como **High Forensic**.

## Capturas de tela

![image_001 - entrada no menu do cPanel](images/cpanel-hf-plugin-01.webp)  
![image_002 - alternância no Feature Manager](images/cpanel-hf-plugin-02.webp)  
![image_003 - painel principal](images/cpanel-hf-plugin-03.webp)  
![image_004 - modal de resultado da auditoria](images/cpanel-hf-plugin-04.webp)  
![image_005 - linha do tempo de evidências e cartões de risco](images/cpanel-hf-plugin-05.webp)  
![image_006 - listagem da quarentena](images/cpanel-hf-plugin-06.webp)  
![image_007 - fluxo de restauração da quarentena](images/cpanel-hf-plugin-07.webp)  
![image_008 - fluxo de confirmação de exclusão](images/cpanel-hf-plugin-08.webp)

## Estrutura do repositório

A estrutura do pacote é:

```text
cpanel-hf-plugin/
├── install.json
├── meta.json
├── hf-icon.png
├── hforensic/
│   ├── forensic.php
│   ├── forensic.live.php
│   └── bin/
│       └── run_hforensic.sh
└── scripts/
    ├── install.sh
    ├── one_shot_install.sh
    ├── uninstall.sh
    ├── one_shot_uninstall.sh
    └── hf-runweblogs-safe.sh
```

## Principais recursos

High Forensic oferece:

- Listagem de arquivos da conta com navegação por diretórios.
- Execução de auditoria de arquivos pela interface (saída em modal).
- Linha do tempo de evidências e resumo de risco extraídos da saída da auditoria.
- Opções de exportação:
  - Impressão/PDF
  - Saída TXT
  - JSON de evidências
  - Snapshot PNG
- Fluxo de quarentena:
  - Mover arquivo para quarentena
  - Restaurar da quarentena
  - Excluir arquivo
- Atualização de logs usando `runweblogs` através de um wrapper seguro.
- Idioma automático da interface (inglês e português) baseado no locale da conta cPanel.

## Arquitetura em tempo de execução

High Forensic utiliza três camadas de execução:

1. **Camada UI/API**: `hforensic/forensic.live.php`  
2. **Executor de auditoria**: `hforensic/bin/run_hforensic.sh`  
3. **Motor de auditoria**: `hf.sh` global em `/usr/local/bin/hf.sh`

`forensic.php` é um redirecionamento de compatibilidade para `forensic.live.php`, para que URLs diretas continuem funcionando enquanto preservam o comportamento de integração `.live.php` do cPanel.

## Requisitos

O plugin requer:

- Servidor cPanel com o tema **Jupiter** instalado.
- Acesso root para operações de instalação/desinstalação.
- `tar`, `bash` e utilitários padrão GNU coreutils.
- `curl` ou `wget` para baixar `hf.sh` (fluxo de instalação padrão).
- `visudo` é opcional, mas recomendado para validação do sudoers.

## Instalação

Use o instalador de pacote para implantações em produção.

### Instalar a partir do tarball do plugin (recomendado)

Execute:

**1. Obter Release**

Obtenha as releases em  
https://github.com/sr00t3d/cpanel-hf/releases/

**2. Obter Release**

Envie o arquivo `.tar.gz` para o seu servidor.

**3. Instalar**

Execute:

```bash
PKG="cpanel-hf-plugin.tar.gz" && tar -xOf "$PKG" scripts/one_shot_install.sh | bash -s -- --package "$PWD/$PKG" --theme jupiter
```

Parâmetros opcionais:

- `--hf-url <URL>`: substituir a URL de origem do `hf.sh`.
- `--hf-sha256 <sha256>`: impor um checksum específico para `hf.sh`.
- `--global-hf /usr/local/bin/hf.sh`: alterar o caminho alvo global do `hf.sh`.
- `--no-global-hf`: pular download e exigir `hf.sh` válido já existente.
- `--no-restart`: pular reinício gracioso do `cpsrvd`.

### Instalar a partir da árvore de código extraída

Na raiz do repositório:

```bash
bash scripts/install.sh --theme jupiter
```

## Desinstalação

### Desinstalar a partir do tarball

Execute:

```bash
PKG="cpanel-hf-plugin.tar.gz" && tar -xOf "$PKG" scripts/one_shot_uninstall.sh | bash -s -- --package "$PWD/$PKG" --theme jupiter
```

### Desinstalar a partir da árvore de código extraída

Na raiz do repositório:

```bash
bash scripts/uninstall.sh --theme jupiter
```

## O que o instalador cria ou modifica

O instalador cria ou atualiza:

- `/usr/local/cpanel/base/frontend/jupiter/hforensic/`
- `/usr/local/bin/hf.sh` (baixado da URL configurada)
- `/usr/local/bin/hf-runweblogs-safe`
- `/etc/sudoers.d/hforensic_runweblogs`

Ele também registra o plugin através de:

- `/usr/local/cpanel/scripts/install_plugin`

## Integração com o Feature Manager

`install.json` define:

- `id`: `hforensic`
- `name`: `High Forensic`
- `group_id`: `files`
- `featuremanager`: `true`
- `feature`: `hforensic`
- `uri`: `hforensic/forensic.php`

Isso permite que administradores do servidor ativem ou desativem o acesso usando o **cPanel Feature Manager**.

## Modelo de segurança

Controles de segurança são implementados em cada camada.

### Endurecimento do pacote e instalação

- Validação de entradas do tarball bloqueia caminhos absolutos e traversal (`..`).
- O instalador requer root.
- Verificação de checksum do `hf.sh` é suportada (`--hf-sha256`).
- Validação de marcador do `hf.sh` garante capacidades esperadas antes da ativação.

### Endurecimento da UI/API (`forensic.live.php`)

- Proteção CSRF para ações que alteram estado.
- Aplicação do método HTTP por ação.
- Detecção de conta e validação rigorosa do usuário.
- Normalização de caminhos e rejeição de null byte.
- Verificações de escopo impedem acesso fora de `/home/<cpanel_user>`.
- Rejeição de symlink para operações sensíveis em arquivos.
- Validação de integridade do índice de quarentena usando assinaturas HMAC.
- Cabeçalhos de segurança:
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: same-origin`
  - `X-Frame-Options: SAMEORIGIN`
- Escape de saída para renderização HTML e JavaScript.

### Endurecimento do executor (`run_hforensic.sh`)

- Validação do nome do usuário com regex estrita.
- Requer que o arquivo alvo exista e resolva dentro de `/home/<cpanel_user>/`.
- Impõe tamanho máximo de arquivo (`10 MiB`).
- Rejeita builds incompatíveis ou desatualizadas de `hf.sh`.
- Executa `hf.sh` em modo de usuário não privilegiado.

### Endurecimento da atualização de logs (`hf-runweblogs-safe.sh`)

- Execução apenas como root via sudo.
- O invocador deve corresponder à conta alvo (`SUDO_USER == CP_USER`).
- Executa apenas `/usr/local/cpanel/scripts/runweblogs <cp_user>`.
- Arquivos de lock e stamp por usuário evitam abuso concorrente.
- Intervalo mínimo imposto (padrão `180` segundos).

## Comportamento anti-spam para ações de atualização

High Forensic implementa limitação de atualização em dois níveis:

- Limite de taxa no backend para `refresh_logs`: 180 segundos por conta/ação.
- Cooldown no frontend para **Atualizar logs**, com contagem regressiva visível no botão.

Isso evita abuso em produção por requisições repetidas de atualização de logs.

## Armazenamento de dados

Metadados por conta são armazenados em:

- `/home/<cpanel_user>/.hforensic/`

Incluindo:

- Diretório de quarentena: `quarantine/`
- Metadados do índice de quarentena
- Arquivos de estado local (por exemplo, estado de throttling de atualização)

Diretórios são criados com permissões restritivas (`0700`) sempre que possível.

## Comportamento de idioma

A interface seleciona automaticamente inglês ou português com base no locale da conta cPanel.

O idioma também é propagado para `hf.sh`, para que a saída da auditoria corresponda ao contexto de idioma da conta.

## Limitações operacionais

O design atual limita intencionalmente o escopo:

- Análise forense **apenas em nível de conta** (não em nível root).
- Logs limitados aos dados disponíveis para a conta em modo de usuário.
- O plugin atualmente é direcionado a implantações com tema **Jupiter**.

## Solução de problemas

Comandos comuns de validação:

```bash
/usr/local/cpanel/3rdparty/bin/php -l \
  /usr/local/cpanel/base/frontend/jupiter/hforensic/forensic.live.php

visudo -cf /etc/sudoers.d/hforensic_runweblogs

ls -la /usr/local/cpanel/base/frontend/jupiter/hforensic
```

Se a interface não refletir mudanças recentes em JavaScript, force a atualização da página com `Ctrl+F5`.

## Construir o pacote

Na raiz do repositório:

```bash
tar -czf cpanel-hf-plugin.tar.gz \
  install.json meta.json README.md LICENSE hf-icon.png hforensic scripts
```

## Contexto de revisão por desenvolvedores cPanel

Este plugin foi projetado para perícia de contas segura em produção dentro do cPanel e utiliza pontos de integração nativos do cPanel:

- `install_plugin` / `uninstall_plugin`
- Entrega frontend Jupiter via `.live.php`
- Integração com Feature Manager
- Orquestração controlada de `runweblogs`

Uma licença de desenvolvedor cPanel é útil para validar compatibilidade com múltiplas versões e cenários de integração antes de um lançamento mais amplo.

## Aviso Legal

> [!WARNING]
> Este software é fornecido “como está”. Sempre garanta que você possui permissão explícita antes de executá-lo. O autor não é responsável por qualquer uso indevido, consequências legais ou impacto em dados causados por esta ferramenta.

## Tutorial detalhado

Para um guia completo passo a passo, confira meu artigo completo:

👉 **Faça usuários auditarem arquivos enviados no cPanel**  
https://perciocastelo.com.br/blog/make-users-audit-files-upload-in-cpanel.html

## Licença

Este projeto é licenciado sob a **GNU General Public License v3.0**. Consulte o arquivo **LICENSE** para mais detalhes.