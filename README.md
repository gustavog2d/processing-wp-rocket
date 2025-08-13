# Log Processamento de cache / WP-Rocket

**Versão:** 1.0.0  
**Autor:** Gustavo Henrique  
**Requer pelo menos:** WordPress 6.0  
**Requer PHP:** 7.4 ou superior  
**Licença:** GPLv2 ou posterior  
**Text Domain:** processing-wp-rocket

---

## 📌 Descrição

Este plugin exibe um log detalhado sobre o status de processamento de cache do [WP Rocket](https://wp-rocket.me/).  
Ele **não é um plugin oficial do WP Rocket**, mas foi desenvolvido para trabalhar junto com ele, fornecendo uma visão consolidada das tabelas internas usadas no processo de cache.

Permite identificar rapidamente URLs com falhas de processamento, status pendentes e informações adicionais para diagnóstico.

---

## 🖼 Exemplo de visualização

![Exemplo de tela do plugin](assets/screenshot-1.png)

> O exemplo acima mostra a listagem com colunas de status, cores de identificação e links diretos para as URLs.

---

## ⚙️ Funcionalidades

- Leitura das tabelas internas do WP Rocket (`wpr_rocket_cache`, `wpr_rucss_used_css`, `wpr_lazy_render_content`, `wpr_above_the_fold`).
- Exibição consolidada em tabela única no painel administrativo.
- Identificação visual por status (cores diferentes para sucesso, erro, pendente, etc.).
- Links diretos para cada URL processada.
- Totalmente independente do WP Rocket (nenhuma modificação é feita nas tabelas originais).

---

## 📥 Instalação

1. Baixe o arquivo `.zip` do plugin.
2. No painel do WordPress, vá em **Plugins > Adicionar novo > Enviar plugin**.
3. Faça upload do arquivo `.zip` e clique em **Ativar**.
4. O menu **WP Rocket Log** será adicionado no painel lateral do WordPress.

---

## 🚀 Como usar

1. Com o WP Rocket ativo no site, acesse o menu **Log WP-Rocket** no painel.
2. A tabela exibirá as URLs processadas pelo WP Rocket, com status e datas.
3. Clique no link de qualquer URL para abrir a página correspondente.

---

## 📄 Notas importantes

- Este plugin **não altera** nem remove dados do WP Rocket — apenas lê as informações já existentes.
- O desempenho da listagem depende do volume de dados nas tabelas do WP Rocket.  
  Para instalações com milhares de registros, pode ser necessário limitar o número de resultados.
- É recomendável manter o WP Rocket sempre atualizado para compatibilidade.

---

## 🔒 Segurança e Boas Práticas

- Apenas usuários com permissão de administrador (`manage_options`) podem acessar a página.
- Todas as URLs e classes CSS são devidamente escapadas para evitar vulnerabilidades XSS.
- Não há execução de queries dinâmicas sem sanitização.

---

## 🛠 Compatibilidade

- **WordPress:** 6.0 ou superior  
- **PHP:** 7.4 ou superior  
- Compatível com o **WP Rocket 3.14+** (pode funcionar em versões anteriores, mas não testado)

---

## 📜 Licença

Este plugin é distribuído sob a licença GPLv2 ou posterior.  
Você pode usá-lo e modificá-lo livremente, desde que mantenha o crédito ao autor.

---