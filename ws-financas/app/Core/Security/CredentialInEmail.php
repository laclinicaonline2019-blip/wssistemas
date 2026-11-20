<?php

namespace App\Core\Security;

/** E-mail bloqueado porque continha uma credencial do .env (a mensagem nunca traz o valor). */
final class CredentialInEmail extends \RuntimeException {}
