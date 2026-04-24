<?php

namespace App\Console\Commands;

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\User;
use App\Models\ApiToken;
use Illuminate\Console\Command;

class IssueApiToken extends Command
{
    protected $signature = 'token:issue
        {--name= : Descriptive name for the token}
        {--user-email= : Email of user this token is bound to (for Gate context)}
        {--app-code= : App code this token scopes to (must match rbac apps.code)}
        {--permissions= : Comma-separated permission codes, e.g. "journal.create,journal.post"}
        {--expires= : Optional ISO8601 expiry, e.g. 2027-01-01T00:00:00Z}';

    protected $description = 'Issue a new scoped API token. Prints the plain token ONCE — copy it immediately.';

    public function handle(): int
    {
        $name = (string) $this->option('name');
        $email = (string) $this->option('user-email');
        $appCode = (string) $this->option('app-code');
        $permCsv = (string) $this->option('permissions');

        if ($name === '' || $email === '' || $appCode === '' || $permCsv === '') {
            $this->error('--name, --user-email, --app-code, --permissions are all required.');

            return self::INVALID;
        }

        $user = User::where('email', $email)->first();
        if ($user === null) {
            $this->error("User [{$email}] not found.");

            return self::FAILURE;
        }

        $app = RbacApp::where('code', $appCode)->first();
        if ($app === null) {
            $this->error("App [{$appCode}] not found.");

            return self::FAILURE;
        }

        $permissions = array_values(array_filter(array_map('trim', explode(',', $permCsv))));
        if ($permissions === []) {
            $this->error('--permissions must contain at least one permission code.');

            return self::INVALID;
        }

        $expires = $this->option('expires') ? (string) $this->option('expires') : null;

        [$token, $plain] = ApiToken::issue([
            'name' => $name,
            'user_id' => $user->id,
            'app_id' => $app->id,
            'permissions' => $permissions,
            'expires_at' => $expires,
        ]);

        $this->info('Token issued. COPY NOW — it will not be shown again:');
        $this->line('');
        $this->line('  '.$plain);
        $this->line('');
        $this->line('Metadata:');
        $this->line('  id:           '.$token->id);
        $this->line('  name:         '.$token->name);
        $this->line('  user:         '.$user->email);
        $this->line('  app:          '.$app->code);
        $this->line('  permissions:  '.implode(',', $permissions));
        $this->line('  expires_at:   '.($token->expires_at?->toIso8601String() ?? 'never'));

        return self::SUCCESS;
    }
}
