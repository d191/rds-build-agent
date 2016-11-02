<?php
use RdsSystem\Message;
use RdsSystem\lib\CommandExecutorException;

/**
 * @example dev/services/deploy/misc/tools/runner.php --tool=Deploy_HardMigration -vv
 */
class Cronjob_Tool_Deploy_HardMigration extends RdsSystem\Cron\RabbitDaemon
{
    const MAX_LOG_LENGTH = 100000;
    const LOG_LAG_TIME = 0.1; // an: в секундах

    /**
     * @return array
     */
    public static function getCommandLineSpec()
    {
        return [
            'worker-name' => [
                'desc' => 'Name of worker',
                'required' => true,
                'valueRequired' => true,
                'useForBaseName' => true,
            ],
        ] + parent::getCommandLineSpec();
    }

    /**
     * @param \Cronjob\ICronjob $cronJob
     */
    public function run(\Cronjob\ICronjob $cronJob)
    {
        $model  = $this->getMessagingModel($cronJob);
        $workerName = $cronJob->getOption('worker-name');

        $model->getHardMigrationTask($workerName, false, function (\RdsSystem\Message\HardMigrationTask $task) use ($workerName, $model) {
            try {
                // an: Должно быть такое же, как в rebuild-package.sh
                $filename = "/home/release/buildroot/$task->project-$task->version/var/pkg/$task->project-$task->version/misc/tools/migration.php";

                // an: Это для препрода
                if (!file_exists($filename)) {
                    $filename = "/var/pkg/$task->project-$task->version/misc/tools/migration.php";
                }

                if (Config::getInstance()->debug) {
                    if ($task->project == 'comon') {
                        $filename = __DIR__ . "/../../../../../../comon/misc/tools/migration.php";
                    } else {
                        $filename = __DIR__ . "/../../../../../$task->project/misc/tools/migration.php";
                    }
                }

                $this->debugLogger->message($filename);

                $model->sendHardMigrationStatus(new \RdsSystem\Message\HardMigrationStatus($task->migration, 'process'));

                $host = Cronjob_Tool_Deploy_HardMigrationProxy::LISTEN_HOST;
                $port = Cronjob_Tool_Deploy_HardMigrationProxy::LISTEN_PORT;
                $command = "php $filename migration --type=hard --project=$task->project --progressHost=$host --progressPort=$port upOne ".str_replace("/", "\\\\", $task->migration)." -vv 2>&1";

                $output = "";
                $t = microtime(true);
                $chunk = "";
                ob_start(function ($string) use ($model, $task, &$t, &$chunk, &$output) {
                    $chunk .= $string;
                    $output = substr($output . $string, -self::MAX_LOG_LENGTH);

                    fwrite(STDOUT, $string);

                    if (microtime(true) - $t > self::LOG_LAG_TIME) {
                        $t = microtime(true);
                        $model->sendHardMigrationLogChunk(new \RdsSystem\Message\HardMigrationLogChunk($task->migration, $chunk));
                        $chunk = "";
                    }
                }, 10);

                system($command, $returnVar);

                ob_end_clean();

                // an: У нас может остаться кусочек логов за последние 100мс работы миграции, и в этом случае его нужно дослать
                if ($chunk) {
                    $model->sendHardMigrationLogChunk(new \RdsSystem\Message\HardMigrationLogChunk($task->migration, $chunk));
                }

                if ($returnVar) {
                    throw new CommandExecutorException("Return var is non-zero, code=" . $returnVar . ", command=$command", $returnVar, $output);
                }

                $model->sendHardMigrationStatus(new \RdsSystem\Message\HardMigrationStatus($task->migration, 'done'));
            } catch (CommandExecutorException $e) {
                // an: 66 - это остановка миграции из RDS
                if ($e->getCode() == 66) {
                    $this->debugLogger->message("Stopped migration via RDS signal");
                    $model->sendHardMigrationStatus(new \RdsSystem\Message\HardMigrationStatus($task->migration, 'stopped'));
                } elseif ($e->getCode() == 67) {
                    $this->debugLogger->message("Migration is not ready yet");
                    $model->sendHardMigrationStatus(new \RdsSystem\Message\HardMigrationStatus($task->migration, 'new'));
                } else {
                    $this->debugLogger->error($e->getMessage());
                    $this->debugLogger->info($e->output);
                    $model->sendHardMigrationStatus(new \RdsSystem\Message\HardMigrationStatus($task->migration, 'failed'));
                }
            } catch (Exception $e) {
                $model->sendHardMigrationStatus(new \RdsSystem\Message\HardMigrationStatus($task->migration, 'failed', $e->getMessage()));
            }

            $task->accepted();
        });

        $this->debugLogger->message("Start listening");

        $this->waitForMessages($model, $cronJob);
    }
}
