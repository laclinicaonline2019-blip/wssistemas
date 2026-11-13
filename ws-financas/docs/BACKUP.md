# Backups e restauração

* **Automático:** banco completo todo dia às 02:10; aos domingos o backup é seguido de um **teste de restauração** automático (o arquivo é restaurado num banco temporário e as contagens de todas as tabelas são comparadas). Resultado na auditoria e em *Proprietário → Sistema*.
* **Formato:** JSON de todas as tabelas, compactado e criptografado com AES-256-GCM (chave derivada de `BACKUP_PASSWORD`, PBKDF2). Gerado pelo próprio PHP — funciona sem `mysqldump`.
* **Local:** `storage/app/backups/*.wsfbak` (mantém os últimos `BACKUP_MANTER`, padrão 14).
* **Cópia externa (obrigatória):** baixe semanalmente pelo Gerenciador de Arquivos ou configure o *Backup* do cPanel/um script rclone para Google Drive/S3. Um backup que só existe no mesmo servidor não protege contra perda da hospedagem.

Comandos:
```bash
php artisan wsf:backup --verificar        # gera e testa
php artisan wsf:backup:verify [arquivo]   # testa um arquivo
php artisan wsf:backup:restore arquivo.wsfbak --force   # repõe registros ausentes (não apaga nem sobrescreve)
```
**Restauração sem perda (1.1.1):** só são inseridos registros que não existem no banco atual; registros existentes e diferentes do backup são mantidos como estão e informados no resultado. A auditoria só é preenchida a partir do backup se estiver vazia (banco novo), em ordem, respeitando os triggers — detalhes em `AUDITORIA.md`.

A restauração exige a mesma `BACKUP_PASSWORD` e, para ler documentos/telefones criptografados, a mesma `APP_KEY`.

**Ensaio recomendado (mensal):** restaure o backup mais recente no ambiente de homologação e confira login, painel e relatórios.
