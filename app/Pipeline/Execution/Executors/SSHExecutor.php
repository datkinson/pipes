<?php

namespace App\Pipeline\Execution\Executors;

use App\Models\Action;
use App\Pipeline\Execution\Executor;
use Ssh\Session;
use Ssh\Configuration;
use Ssh\Authentication\Password;
use Ssh\Authentication\PublicKeyFile;
use Illuminate\Filesystem\Filesystem as File;

class SSHExecutor extends Executor
{
    /**
     * Type of executor
     * E.g. SSH, Docker
     *
     * @var string
     */
    protected $type = 'SSH';

    /**
     * Executes the action using the executor
     *
     * @param Action $action Action to execute
     *
     * @return array Array of data returned from commands
     */
    public function execute(Action $action)
    {
        if ($action->host === null) {
            return false;
        }

        $auth = $action->host->auth;

        if ($auth === null) {
            return false;
        }

        $commands = $action->commands;

        $configuration = new Configuration($action->host->host);
        $session = $this->getSSHSession($auth, $configuration);
        $exec = $session->getExec();

        $commandOutputs = [];
        foreach ($commands as $command) {
            $commandOutputs[] = $exec->run($command->command);
        }
        foreach ($commandOutputs as $output) {
            \Log::info($output);
        }

        return true;
    }

    /**
     * Gets the SSH session using the auth given
     *
     * @param \App\Models\Auth $auth Auth model
     * @param \Ssh\Configuration $configuration SSH configuration object
     */
    protected function getSSHSession($auth, $configuration)
    {
        if ($auth->isKeyAuthentication()) {
            $keyPath = 'storage/ssh/keys/' . rand(1000000, 9999999);
            $keyPathPublic = $keyPath . '.pub';
            $file = new File();
            $file->put($keyPath, $auth->credentials->key);
            $file->put($keyPathPublic, $auth->credentials->key_public);
            $authentication = new PublicKeyFile(
                $auth->credentials->username,
                $keyPathPublic,
                $keyPath
            );
        } else {
            $authentication = new Password(
                $auth->credentials->username,
                $auth->credentials->password
            );
        }
        $session = new Session($configuration, $authentication);
        $file->delete($keyPath, $keyPathPublic);
        return $session;
    }
}
