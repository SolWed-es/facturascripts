<?php
/**
 * Regression test for EditUser role rows. Run without config.php, Composer or a database:
 * php Test/Isolated/EditUserRoles.php [alternate-EditUser.php] [alternate-core-root]
 *
 * Uses the real EditUser, PanelController, request and token validation. Only the
 * database records, view form mapping and token storage are kept in memory.
 */

namespace FacturaScripts\Core {
    final class Tools
    {
        public static function log(string $channel = ''): \FacturaScripts\Core\Base\MiniLog
        {
            return new \FacturaScripts\Core\Base\MiniLog($channel);
        }
    }
}

namespace FacturaScripts\Dinamic\Model {
    class User
    {
        public $nick = '';
        public $admin = false;
        public $enabled = true;

        public function load($nick): bool
        {
            $this->nick = (string)$nick;
            return in_array($this->nick, ['operator', 'employee'], true);
        }

        public function exists(): bool
        {
            return $this->nick !== '';
        }
    }
}

namespace FacturaScripts\Test\Isolated {
    use FacturaScripts\Core\Base\ControllerPermissions;
    use FacturaScripts\Core\Base\MiniLog;
    use FacturaScripts\Core\Controller\EditUser;
    use FacturaScripts\Core\Lib\ExtendedController\BaseView;
    use FacturaScripts\Core\Lib\MultiRequestProtection;
    use FacturaScripts\Core\Request;
    use FacturaScripts\Dinamic\Model\User;

    define('FS_ITEM_LIMIT', 50);
    $root = dirname(__DIR__, 2);
    $alternateRoot = $argv[2] ?? $root;
    spl_autoload_register(static function (string $class) use ($root, $alternateRoot): void {
        $prefix = 'FacturaScripts\\Core\\';
        if (strpos($class, $prefix) === 0) {
            $path = '/Core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            require_once (is_file($alternateRoot . $path) ? $alternateRoot : $root) . $path;
        }
    });
    require_once $argv[1] ?? $root . '/Core/Controller/EditUser.php';

    final class MemoryTokens extends MultiRequestProtection
    {
        private $tokens = [];

        public function clearSeed(): void
        {
            self::$seed = 'isolated-test-seed';
        }

        protected function getTokens(): array
        {
            return $this->tokens;
        }

        protected function saveToken(string $token): bool
        {
            $this->tokens[] = $token;
            return true;
        }
    }

    final class MemoryRole
    {
        public $id = 42;
        public $nick = 'employee';
        public $codrole = 'previous';
        public $saved = 0;
        public $existing = true;

        public function exists(): bool
        {
            return $this->existing;
        }

        public function loadFromCode($code): bool
        {
            return (string)$code === (string)$this->id;
        }

        public function primaryColumnValue()
        {
            return $this->id;
        }

        public function save(): bool
        {
            ++$this->saved;
            return true;
        }
    }

    final class MemoryView extends BaseView
    {
        public function __construct($model)
        {
            $this->model = $model;
        }

        public function export(&$exportManager, $codes): bool
        {
            throw new \LogicException('Unexpected export');
        }

        public function loadData($code = '', $where = [], $order = [], $offset = 0, $limit = FS_ITEM_LIMIT)
        {
            throw new \LogicException('Unexpected view load');
        }

        public function processFormData($request, $case)
        {
            $this->model->nick = $request->input('nick');
            $this->model->codrole = $request->input('codrole');
        }
    }

    final class EditUserHarness extends EditUser
    {
        public function __construct(string $className, string $url = '')
        {
            // Database services are deliberately not initialized by this isolated test.
        }

        public function configure(bool $admin, string $target, string $active = 'EditRoleUser'): self
        {
            // Avoid the application constructor, which initializes database services.
            $this->active = $active;
            $this->user = new User();
            $this->user->nick = 'operator';
            $this->user->admin = $admin;
            $this->permissions = new ControllerPermissions();
            // Even a caller with allowUpdate cannot grant roles without being admin.
            $this->permissions->allowUpdate = true;
            $this->multiRequestProtection = new MemoryTokens();
            $this->request = new Request([
                'query' => ['code' => $target],
                'request' => [
                    'action' => 'edit', 'activetab' => $active, 'code' => '42',
                    'nick' => $target, 'codrole' => 'client',
                    'multireqtoken' => $this->multiRequestProtection->newToken(),
                ],
            ]);
            $record = new MemoryRole();
            $record->nick = $target;
            $this->views['EditUser'] = new MemoryView(new MemoryRole());
            $this->views[$active] = new MemoryView($record);
            return $this;
        }

        public function saveRow(): bool
        {
            return $this->editAction();
        }

        public function addRow(): bool
        {
            $this->record()->existing = false;
            $this->request->request->set('action', 'insert');
            $this->request->request->remove('code');
            return $this->insertAction();
        }

        public function record(): MemoryRole
        {
            return $this->views[$this->active]->model;
        }
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message . '; log=' . json_encode(MiniLog::read()));
        }
    }

    $cases = [
        'admin can still add a new group' => static function (): void {
            $controller = (new EditUserHarness('EditUser'))->configure(true, 'employee');
            check($controller->addRow(), 'New group should be saved');
            check($controller->record()->saved === 1 && $controller->record()->codrole === 'client', 'New role must be saved once');
        },
        'non-admin cannot add a new group' => static function (): void {
            $controller = (new EditUserHarness('EditUser'))->configure(false, 'operator');
            check(!$controller->addRow(), 'Non-admin role insertion must fail');
            check($controller->record()->saved === 0, 'No role may be saved');
        },
        'invalid token cannot add a new group' => static function (): void {
            $controller = (new EditUserHarness('EditUser'))->configure(true, 'employee');
            $controller->request->request->set('multireqtoken', 'invalid');
            check(!$controller->addRow(), 'Invalid token must fail');
            check($controller->record()->saved === 0, 'No role may be saved');
        },
        'admin updates employee group with row ID different from nick' => static function (): void {
            $controller = (new EditUserHarness('EditUser'))->configure(true, 'employee');
            check($controller->saveRow(), 'Group edit should succeed');
            check($controller->record()->saved === 1 && $controller->record()->codrole === 'client', 'Role must be saved');
            check($controller->record()->id === 42 && $controller->record()->nick === 'employee', 'Assignment identity must be preserved');
        },
        'admin updates own group without user profile fields' => static function (): void {
            $controller = (new EditUserHarness('EditUser'))->configure(true, 'operator');
            check($controller->saveRow(), 'Own group edit should succeed');
            check($controller->record()->saved === 1, 'Role must be saved once');
        },
        'non-admin cannot update own group even with allowUpdate' => static function (): void {
            $controller = (new EditUserHarness('EditUser'))->configure(false, 'operator');
            check(!$controller->saveRow(), 'Non-admin role edit must fail');
            check($controller->record()->saved === 0, 'No role may be saved');
        },
        'non-admin cannot update another user group' => static function (): void {
            $controller = (new EditUserHarness('EditUser'))->configure(false, 'employee');
            check(!$controller->saveRow(), 'Non-admin role edit must fail');
            check($controller->record()->saved === 0, 'No role may be saved');
        },
        'invalid token cannot save a group' => static function (): void {
            $controller = (new EditUserHarness('EditUser'))->configure(true, 'employee');
            $controller->request->request->set('multireqtoken', 'invalid');
            check(!$controller->saveRow(), 'Invalid token must fail');
            check($controller->record()->saved === 0, 'No role may be saved');
        },
        'repeated token cannot save twice' => static function (): void {
            $controller = (new EditUserHarness('EditUser'))->configure(true, 'employee');
            check($controller->saveRow(), 'First request should succeed');
            check(!$controller->saveRow(), 'Repeated token must fail');
            check($controller->record()->saved === 1, 'Only first request may save');
        },
        'missing assignment cannot be saved' => static function (): void {
            $controller = (new EditUserHarness('EditUser'))->configure(true, 'employee');
            $controller->request->request->set('code', '99');
            check(!$controller->saveRow(), 'Unknown assignment must fail');
            check($controller->record()->saved === 0, 'No role may be saved');
        },
        'main user view still rejects nick changes' => static function (): void {
            $controller = (new EditUserHarness('EditUser'))->configure(true, 'employee', 'EditUser');
            $controller->request->request->set('code', 'employee');
            $controller->request->request->set('nick', 'changed');
            check(!$controller->saveRow(), 'Renaming a user must fail');
            check($controller->record()->saved === 0, 'No user may be saved');
        },
    ];
    $failed = 0;
    foreach ($cases as $name => $case) {
        MiniLog::clear();
        try {
            $case();
            echo 'PASS: ' . $name . PHP_EOL;
        } catch (\Throwable $error) {
            ++$failed;
            echo 'FAIL: ' . $name . ': ' . $error->getMessage() . PHP_EOL;
        }
    }
    echo count($cases) . ' cases, ' . $failed . ' failures' . PHP_EOL;
    exit($failed === 0 ? 0 : 1);
}
