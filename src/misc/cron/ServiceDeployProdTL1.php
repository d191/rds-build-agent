<?php
use \Cronjob\ConfigGenerator;
use \Cronjob\ConfigGenerator\Comment;
use \Cronjob\ConfigGenerator\MultiCronCommand;
use \Cronjob\ConfigGenerator\CronCommand;
use \Cronjob\ConfigGenerator\SimpleCommand;
use \Cronjob\ConfigGenerator\PeriodicCommand;
use \Cronjob\ConfigGenerator\MultiCommandToCron;
use \Cronjob\ConfigGenerator\MultiPeriodicCommand;

class ServiceDeployProdTL1
{
    public function getCronConfigRows()
    {
        $allCommands = [
            new CronCommand(new PeriodicCommand(\Cronjob_Tool_Deploy_Deploy::getToolCommand(['--max-duration=60'], $verbosity=3), 5), '* * * * *'),
            new CronCommand(new PeriodicCommand(\Cronjob_Tool_Deploy_Use::getToolCommand(['--max-duration=60'], $verbosity=3), 1), '* * * * *'),
            new CronCommand(new PeriodicCommand(\Cronjob_Tool_Deploy_Killer::getToolCommand(['--max-duration=60'], $verbosity=3), 3), '* * * * *'),
            new CronCommand(new PeriodicCommand(\Cronjob_Tool_Deploy_Migration::getToolCommand(['--max-duration=60'], $verbosity=3), 3), '* * * * *'),
            new CronCommand(new PeriodicCommand(\Cronjob_Tool_Deploy_HardMigration::getToolCommand([], $verbosity=3), 1), '* * * * *'),
            new CronCommand(new PeriodicCommand(\Cronjob_Tool_Deploy_HardMigrationProxy::getToolCommand([], $verbosity=3), 1), '* * * * *'),
            new CronCommand(new PeriodicCommand(\Cronjob_Tool_Deploy_GarbageCollector::getToolCommand([], $verbosity=3), 86400), '* * * * *'),
        ];

        $allCommands = new MultiCronCommand($allCommands);

        $rows = $allCommands->getCronConfigRows();

        return array_merge($this->getEnv(), $rows);
    }

    protected function getEnv()
    {
        return [
            'MAILTO=adm+ny_cron@whotrades.org',
            'CRONJOB_TOOLS=/var/www/service-deploy/misc/tools',
            'PATH=/usr/local/bin:/usr/bin:/bin',
            'TERM=xterm',
        ];
    }
}