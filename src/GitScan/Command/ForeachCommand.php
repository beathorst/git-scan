<?php
namespace GitScan\Command;

use GitScan\GitRepo;
use GitScan\Util\Filesystem;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ForeachCommand extends BaseCommand {

  /**
   * @var Filesystem
   */
  var $fs;

  /**
   * @param string|null $name
   * @param array $parameters list of configuration parameters to accept ($key => $label)
   */
  public function __construct($name = NULL) {
    $this->fs = new Filesystem();
    parent::__construct($name);
  }

  protected function configure() {
    $this
      ->setName('foreach')
      ->setDescription('Execute a shell command on all nested repositories')
      ->setHelp(""
        . "Execute a shell command on all tested repositories\n"
        . "\n"
        . "The following shell variables can be used within a command:\n"
        . " * \$path - The relative path of the git repository\n"
        . " * \$toplevel - The absolute path of the directory being searched\n"
        . "\n"
        . "Examples:\n"
        . "   foreach -c 'echo Examine \$path in \$toplevel'\n"
        . "   foreach -c 'echo Examine \$path in \$toplevel' --status=boring\n"
        . "   foreach /home/me/download /home/me/src -c 'echo Examine \$path in \$toplevel'\n"
        . "\n"
        . "Important: The example uses single-quotes to escape the $'s\n"
    )
      ->addArgument('path', InputArgument::IS_ARRAY, 'The local base path to search', array(getcwd()))
      ->addOption('command', 'c', InputOption::VALUE_REQUIRED, 'The command to execute')
      ->addOption('status', NULL, InputOption::VALUE_REQUIRED, 'Filter table output by repo statuses ("all","novel","boring")', 'all');
  }

  //public function getSynopsis() {
//    return $this->getName() . ' [--status="..."] [--path="..."] [command]';
  //}

  protected function initialize(InputInterface $input, OutputInterface $output) {
    $input->setArgument('path', $this->fs->toAbsolutePaths($input->getArgument('path')));
    $this->fs->validateExists($input->getArgument('path'));
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    if (!$input->getOption('command')) {
      $output->writeln("<error>Missing required option: --command</error>");
      return 1;
    }

    $statusCode = 0;

    if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
      $output->writeln("<info>[[ Finding repositories ]]</info>");
    }
    $scanner = new \GitScan\GitRepoScanner();
    $gitRepos = $scanner->scan($input->getArgument('path'));

    foreach ($gitRepos as $gitRepo) {
      /** @var \GitScan\GitRepo $gitRepo */
      if (!$gitRepo->matchesStatus($input->getOption('status'))) {
        continue;
      }

      $topLevel = $this->fs->findFirstParent($gitRepo->getPath(), $input->getArgument('path'));

      if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
        $output->writeln("<info>[[ {$gitRepo->getPath()} ]]</info>");
      }
      $process = new \Symfony\Component\Process\Process($input->getOption('command'));
      $process->setWorkingDirectory($gitRepo->getPath());
      // $process->setEnv(...); sucks in Debian/Ubuntu
      putenv("path=" . $this->fs->makePathRelative($gitRepo->getPath(), $topLevel));
      putenv("toplevel=" . $topLevel);
      $errorOutput = $output;
      if (is_callable($output, 'getErrorOutput') && $output->getErrorOutput()) {
        $errorOutput = $output->getErrorOutput();
      }
      $process->run(function ($type, $buffer) use ($output, $errorOutput) {
        if (\Symfony\Component\Process\Process::ERR === $type) {
          if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $errorOutput->write("<error>STDERR</error> ");
          }
          $errorOutput->write($buffer, false, OutputInterface::OUTPUT_RAW);
        }
        else {
          if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
            $output->write("<comment>STDOUT</comment> ");
          }
          $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
        }
      });
      if (!$process->isSuccessful()) {
        $errorOutput->writeln("<error>[[ {$gitRepo->getPath()}: exit code = {$process->getExitCode()} ]]</error>");
        $statusCode = 2;
      }
    }
    putenv("path");
    putenv("toplevel");

    return $statusCode;
  }
}