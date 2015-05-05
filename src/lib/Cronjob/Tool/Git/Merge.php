<?php
use RdsSystem\Message;
use RdsSystem\lib\CommandExecutor;
use RdsSystem\lib\CommandExecutorException;

/**
 * @example dev/services/deploy/misc/tools/runner.php --tool=Git_Merge -vv
 */
class Cronjob_Tool_Git_Merge extends RdsSystem\Cron\RabbitDaemon
{
    const PACKAGES_TIMEOUT = 60;

    /** @var CommandExecutor */
    private $commandExecutor;

    private $allowedBranches = [];
    private $disallowedBranches = [];

    /**
     * Use this function to get command line spec for cronjob
     * @return array
     */
    public static function getCommandLineSpec()
    {
        return [
            'instance' => [
                'desc' => 'Instance number of process',
                'valueRequired' => true,
                'required' => true,
                'useForBaseName' => true,
            ],
            'allowed-branches' => [
                'desc' => 'List of branches, exploded by coma which are allowed to process',
                'valueRequired' => true,
                'required' => false,
            ],
            'disallowed-branches' => [
                'desc' => 'List of branches, exploded by coma which are disallowed to process',
                'valueRequired' => true,
                'required' => false,
            ],
        ] + parent::getCommandLineSpec();
    }

    /**
     * Performs actual work
     */
    public function run(\Cronjob\ICronjob $cronJob)
    {
        ini_set("memory_limit", "1G");
        $model  = $this->getMessagingModel($cronJob);
        $instance = $cronJob->getOption('instance');
        $this->allowedBranches = array_filter(explode(",", $cronJob->getOption('allowed-branches')));
        $this->disallowedBranches = array_filter(explode(",", $cronJob->getOption('disallowed-branches')));

        $model->readMergeTask(false, function(\RdsSystem\Message\Merge\Task $task) use ($model, $instance) {
            $sourceBranch = $task->sourceBranch;
            $targetBranch = $task->targetBranch;

            if (!$this->isBranchAllowed($targetBranch)) {
                $this->debugLogger->message("[!] Skip merge task to branch $targetBranch as it is not allowed");
                $task->retry();
                return;
            }

            $autoConflictResolveEnabled = true;

            $this->debugLogger->message("Merging $sourceBranch to $targetBranch");

            if ($autoConflictResolveEnabled) {
                $this->debugLogger->message("[!] Auto resolve conflicts enabled");
            }

            $dir = self::fetchRepositories($this->debugLogger, $instance);

            $this->commandExecutor = new CommandExecutor($this->debugLogger);

            $bashDir = dirname(dirname(dirname(dirname(__DIR__))))."/misc/tools/bash/";
            $errors = [];
            try {

                $cmd = "(cd $dir; node git-tools/alias/git-all.js git fetch)";
                $this->commandExecutor->executeCommand($cmd);

                //an: clean up
                $cmd = "(cd $dir; node git-tools/alias/git-all.js rm -fr .git/rebase-apply)";
                $this->commandExecutor->executeCommand($cmd);

                $cmd = "(cd $dir; node git-tools/alias/git-all.js git reset origin/master --hard)";
                $this->commandExecutor->executeCommand($cmd);

                $cmd = "(cd $dir; node git-tools/alias/git-all.js git checkout master)";
                $this->commandExecutor->executeCommand($cmd);

                $cmd = "(cd $dir; node git-tools/alias/git-all.js git reset origin/master --hard)";
                $this->commandExecutor->executeCommand($cmd);

                $cmd = "(cd $dir; node git-tools/alias/git-all.js git clean -fd)";
                $this->commandExecutor->executeCommand($cmd);

                if ($autoConflictResolveEnabled) {
                    //an: Учимся разрешать конфликты
                    $branch = 'develop';
                    $file = ".git/rerere-$branch";
                    $cmd = "(cd $dir; node git-tools/alias/git-all.js 'if [ -f $file ]; then start=`cat $file`; bash $bashDir/rerere-train.sh \$start..origin/$branch; else bash $bashDir/rerere-train.sh --max-count=100 $branch; fi; git log origin/$branch -1 --pretty=%H > $file;')";
                    $this->commandExecutor->executeCommand($cmd);

                    $branch = 'staging';
                    $file = ".git/rerere-$branch";
                    $cmd = "(cd $dir; node git-tools/alias/git-all.js 'if [ -f $file ]; then start=`cat $file`; bash $bashDir/rerere-train.sh \$start..origin/$branch; else bash $bashDir/rerere-train.sh --max-count=100 $branch; fi; git log origin/$branch -1 --pretty=%H > $file;')";
                    $this->commandExecutor->executeCommand($cmd);
                }

                //an: source branch
                $cmd = "(cd $dir; node git-tools/alias/git-all.js \"git checkout $task->sourceBranch || git checkout -b $task->sourceBranch\")";
                $this->commandExecutor->executeCommand($cmd);

                $cmd = "cd $dir && node git-tools/alias/git-all.js 'git ls-remote --exit-code origin refs/heads/$task->sourceBranch; code=$?; if [ \$code -eq 2 ]; then echo branch $task->sourceBranch not exists at remote; else if [ \$code -eq 0 ]; then git branch --set-upstream $task->sourceBranch origin/$task->sourceBranch; else exit 10; fi; fi;'";
                $output = $this->commandExecutor->executeCommand($cmd);
                $this->debugLogger->insane($output);

                $cmd = "(cd $dir; node git-tools/alias/git-all.js \"(git rev-parse --abbrev-ref $task->sourceBranch@{upstream} && git reset origin/$task->sourceBranch --hard) || echo branch $task->sourceBranch not exists\")";
                $this->commandExecutor->executeCommand($cmd);

                $cmd = "(cd $dir; node git-tools/alias/git-all.js git pull --fast)";
                $this->commandExecutor->executeCommand($cmd);

                //: target branch
                $cmd = "(cd $dir; node git-tools/alias/git-all.js \"git checkout $task->targetBranch || git checkout -b $task->targetBranch\")";
                $this->commandExecutor->executeCommand($cmd);


                $cmd = "cd $dir && node git-tools/alias/git-all.js \"git rev-parse --abbrev-ref $task->targetBranch@{upstream} || (echo 'pushing branch' && git push -u origin $task->targetBranch:$task->targetBranch)\"";
                exec($cmd, $output, $returnVar);

                $cmd = "(cd $dir; node git-tools/alias/git-all.js git reset origin/$task->targetBranch --hard)";
                $this->commandExecutor->executeCommand($cmd);

                $cmd = "(cd $dir; node git-tools/alias/git-all.js git pull --fast)";
                $this->commandExecutor->executeCommand($cmd);

                if ($autoConflictResolveEnabled) {
                    //an: Мержим с использованием http://git-scm.com/blog/2010/03/08/rerere.html. Это позволяет автоматически разрешать конфликты, разрешенные ранее
                    $cmd = "(cd $dir; node git-tools/alias/git-all.js 'git merge $task->sourceBranch || (if [ `git status --porcelain|grep -vE '^M '|grep -vE '^A '|wc -l` -eq 0 ]; then git commit -m \"auto resolve conflict using previous resolution\"; exit $?; else exit 1; fi;)')";
                } else {
                    //an: обычный мерж
                    $cmd = "(cd $dir; node git-tools/alias/git-all.js git merge $task->sourceBranch)";
                }

                try {
                    $output = $this->commandExecutor->executeCommand($cmd);

                    if ($autoConflictResolveEnabled) {
                        $this->debugLogger->message("Output: ".json_encode($output));
                    }
                } catch (\RdsSystem\lib\CommandExecutorException $e) {
                    if ($e->getCode() == 1) {
                        //an: Ошибка мержа
                        $this->debugLogger->message("Conflict detected, $e->output");
                        $errors[] = "Conflicts detected: \n".$this->parseMergeOutput($e->output);
                    } else {
                        throw $e;
                    }
                }
            } catch (\RdsSystem\lib\CommandExecutorException $e) {
                $this->debugLogger->message("Unknown error during merge, $e->output");
                $errors[] = "Unknown error during merge: $e->output";
            }


            if (empty($errors)) {
                try {
                    $this->debugLogger->message("No errors during merge, pushing changes");

                    if ($targetBranch == "master") {
                        $semaphore = new \Semaphore($this->debugLogger, \Config::getInstance()->semaphore_dir."/merge_deploy.smp");
                        $this->debugLogger->message("Locking semaphore");
                        $semaphore->lock();
                        $this->debugLogger->message("Locked semaphore");
                    }
                    $cmd = "(cd $dir; node git-tools/alias/git-all.js git push)";
                    if (!\Config::getInstance()->mergeDryRun) {
                        $this->commandExecutor->executeCommand($cmd);
                    } else {
                        sleep(3);
                        $this->debugLogger->message("Skip pushing as Config::mergeDryRun set to true");
                    }
                } catch (\RdsSystem\lib\CommandExecutorException $e) {
                    $this->debugLogger->message("Unknown error during pushing merge: $e->output");
                    $errors[] = "Unknown error during pushing merge: $e->output";
                }
                if (!empty($semaphore)) {
                    $semaphore->unlock();
                    $this->debugLogger->message("Unlocking semaphore");
                    unset($semaphore);
                    $this->debugLogger->message("Unlocked semaphore");
                }
            } else {
                $this->debugLogger->message("Merge errors detected, skip pushing");
            }

            $this->debugLogger->debug("Sending reply with id=$task->featureId...");
            $model->sendMergeTaskResult(new Message\Merge\TaskResult($task->featureId, $task->sourceBranch, $task->targetBranch, empty($errors), $errors, $task->type));

            $this->debugLogger->message("Task accepted");
            $task->accepted();
        });

        $model->readMergeCreateBranch(false, function(Message\Merge\CreateBranch $task) use ($instance) {
            $this->debugLogger->message("Creating branch $task->branch from $task->source");

            if (empty($task->source)) {
                throw new Exception("Empty source branch, can't create branch $task->branch from empty");
            }

            $dir = self::fetchRepositories($this->debugLogger, $instance);

            $this->commandExecutor = new CommandExecutor($this->debugLogger);

            $cmd = "(cd $dir; node git-tools/alias/git-all.js git fetch)";
            $this->commandExecutor->executeCommand($cmd);

            $cmd = "(cd $dir; node git-tools/alias/git-all.js git checkout $task->source)";
            $this->commandExecutor->executeCommand($cmd);

            $cmd = "(cd $dir; node git-tools/alias/git-all.js git reset origin/$task->source --hard)";
            $this->commandExecutor->executeCommand($cmd);

            $cmd = "(cd $dir; node git-tools/alias/git-all.js git push origin $task->source:$task->branch".($task->force ? " --force" : "").")";
            $this->commandExecutor->executeCommand($cmd);

            $task->accepted();
            $this->debugLogger->message("Task accepted");
        });

        $this->waitForMessages($model, $cronJob);
    }

    private function isBranchAllowed($branch)
    {
        //an: Если наша ветка в списке запрещенных - она запрещена
        if (!empty($this->disallowedBranches) && in_array($branch, $this->disallowedBranches)) {
            return false;
        }

        //an: Если нашей ветке нет в списке разрешенных - она запрещена
        if (!empty($this->allowedBranches) && !in_array($branch, $this->allowedBranches)) {
            return false;
        }

        //an: Если оба списка пустые - ветка разрешена
        return true;
    }

    /**
     * Парсит вывод git-all.js git merge и возвращает форматированный текст
     * @param $text
     * @return array
     */
    private function parseMergeOutput($text)
    {
        $text = str_replace("script worked", ">>>", $text);;
        $text = preg_replace('~>>>\s+'.realpath(\Config::getInstance()->mergePoolDir).'~', "$0\n$0", $text);;

        $regex = '~>>>\s+'.realpath(\Config::getInstance()->mergePoolDir).'/\d+/([\w-]+)\s*(.*?)\s*\n>>>~sui';
        $result = [];
        preg_replace_callback($regex, function($ans) use (&$result){
                    if ($ans[2] == 'Already up-to-date.') {
                        return;
                    }
                    $result[$ans[1]] = $ans[2];
                }, $text.">>>");

        ksort($result);

        $output = "";
        foreach ($result as $repo => $body) {
            $output .= "h6. $repo\n{quote}$body{quote}\n\n";
        }

        return $output;
    }


    private static function getAllRepositories(\ServiceBase_IDebugLogger $debugLogger)
    {
        $url = "http://git.whotrades.net/packages.json";
        $httpSender = new \ServiceBase\HttpRequest\RequestSender($debugLogger);
        $json = $httpSender->getRequest($url, '', self::PACKAGES_TIMEOUT);
        $data = json_decode($json, true);
        if (empty($data)) {
            throw new ApplicationException("Invalid or empty json received from $url: $json");
        }
        $result = [];
        foreach ($data['packages'] as $key => $val) {
            if (false === strpos($val['dev-master']['source']['url'], 'git.whotrades.net')) {
                continue;
            }
            if (!empty(\Config::getInstance()->demoMerge) && false === strpos($key, 'test') && false === strpos($key, 'git-tools')) {
                continue;
            }

            $result[preg_replace('~.*/~', '', $val['dev-master']['source']['url'])] = $val['dev-master']['source']['url'];
        }

        return $result;
    }

    public static function fetchRepositories(\ServiceBase_IDebugLogger $debugLogger, $instance = 0)
    {
        $dir = Config::getInstance()->mergePoolDir.$instance;

        $debugLogger->message("Pool dir: $dir");
        if (!is_dir($dir)) {
            $debugLogger->message("Creating directory $dir");
            mkdir($dir, 0777, true);
        }

        $commandExecutor = new CommandExecutor($debugLogger);
        $repositories = Cronjob_Tool_Git_Merge::getAllRepositories($debugLogger);
        foreach ($repositories as $key => $url) {
            $debugLogger->debug("Processing repository $key ($url)");
            $repoDir = $dir . "/" . $key;

            if (!is_dir($repoDir)) {
                $debugLogger->message("Creating directory $repoDir");
                mkdir($repoDir, 0777, true);
            }

            if (!is_dir($repoDir . "/.git")) {
                $debugLogger->debug("Repository $key not exists as $repoDir, start cloning...");
                mkdir($repoDir, 0777);
                $cmd = "(cd $repoDir; git clone $url .)";
                $commandExecutor->executeCommand($cmd);
            }
        }

        return $dir;
    }
}
