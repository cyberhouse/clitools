<?php

namespace CliTools\Console\Command\Sync;

/*
 * CliTools Command
 * Copyright (C) 2015 Markus Blaschke <markus@familie-blaschke.net>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

use CliTools\Utility\PhpUtility;
use CliTools\Utility\UnixUtility;
use CliTools\Console\Builder\CommandBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

abstract class AbstractCommand extends \CliTools\Console\Command\AbstractCommand {

    const CONFIG_FILE = 'clisync.yml';
    const PATH_DUMP   = '/dump/';
    const PATH_DATA   = '/data/';

    /**
     * Project working path
     *
     * @var string|boolean|null
     */
    protected $workingPath;

    /**
     * Temporary storage dir
     *
     * @var string|null
     */
    protected $tempDir;

    /**
     * Sync configuration
     *
     * @var array
     */
    protected $config = array();

    /**
     * Execute command
     *
     * @param  InputInterface  $input  Input instance
     * @param  OutputInterface $output Output instance
     *
     * @return int|null|void
     * @throws \Exception
     */
    public function execute(InputInterface $input, OutputInterface $output) {
        $this->workingPath = UnixUtility::findFileInDirectortyTree(self::CONFIG_FILE);

        if (empty($this->workingPath)) {
            $this->output->writeln('<error>No ' . self::CONFIG_FILE . ' found in tree</error>');
            return 1;
        }

        $this->output->writeln('<comment>Found ' . self::CONFIG_FILE . ' directory: ' . $this->workingPath . '</comment>');

        $this->readConfiguration();
        $this->startup();

        try {
            $this->runTask();
        } catch (\Exception $e) {
            $this->cleanup();
            throw $e;
        }

        $this->cleanup();
    }

    /**
     * Read and validate configuration
     */
    protected function readConfiguration() {
        $confFile = $this->workingPath . '/' . self::CONFIG_FILE;
        $conf = Yaml::parse(PhpUtility::fileGetContents($confFile));

        if (!empty($conf)) {
            $this->config = new \ArrayObject();
            $this->config->setFlags(\ArrayObject::STD_PROP_LIST|\ArrayObject::ARRAY_AS_PROPS);
            $this->config->exchangeArray($conf);
        } else {
            throw new \RuntimeException('Could not parse "' . $confFile . '"');
        }
    }

    /**
     * Startup task
     */
    protected function startup() {
        $this->tempDir = '/tmp/.clisync-'.getmypid();
        $this->clearTempDir();
        PhpUtility::mkdir($this->tempDir, 0777, true);
        PhpUtility::mkdir($this->tempDir . '/mysql/', 0777, true);
    }

    /**
     * Cleanup task
     */
    protected function cleanup() {
        $this->clearTempDir();
    }

    /**
     * Clear temp. storage directory if exists
     */
    protected function clearTempDir() {
        // Remove storage dir
        if (!empty($this->tempDir) && is_dir($this->tempDir)) {
            $command = new CommandBuilder('rm', '-rf');
            $command->addArgumentSeparator()
                    ->addArgument($this->tempDir);
            $command->executeInteractive();
        }
    }

    /**
     * Create rsync command for share sync
     *
     * @return CommandBuilder
     */
    protected function createShareRsyncCommand($source, $target, $useExcludeInclude = false) {
        $this->output->writeln('<info>Sync from ' . $source . ' to ' . $target . '</info>');

        $command = new CommandBuilder('rsync', '-rlptD --delete-after');

        if ($useExcludeInclude && !empty($this->config->share['rsync']['directory'])) {

            // Add file list (external file with --files-from option)
            if (!empty($this->config->share['rsync']['directory'])) {
                $this->rsyncAddFileList($command,    $this->config->share['rsync']['directory']);
            }

            // Add exclude (external file with --exclude-from option)
            if (!empty($this->config->share['rsync']['exclude'])) {
                $this->rsyncAddExcludeList($command, $this->config->share['rsync']['exclude']);
            }
        }

        // Paths should have leading / to prevent sync issues
        $source = rtrim($source, '/') . '/';
        $target = rtrim($target, '/') . '/';

        // Set source and target
        $command->addArgument($source)
                ->addArgument($target);


        // Register teardown

        return $command;
    }

    /**
     * Add file (pattern) list to rsync command
     *
     * @param CommandBuilder $command Rsync Command
     * @param array          $list    List of files
     */
    protected function rsyncAddFileList(CommandBuilder $command, $list) {
        $rsyncFilter = $this->tempDir . '/.rsync-filelist';

        PhpUtility::filePutContents($rsyncFilter, implode("\n", $list));

        $command->addArgumentTemplate('--files-from=%s', $rsyncFilter);

        // cleanup rsync file
        $command->getExecutor()->addFinisherCallback(function () use ($rsyncFilter) {
            unlink($rsyncFilter);
        });

    }

    /**
     * Add exclude (pattern) list to rsync command
     *
     * @param CommandBuilder $command  Rsync Command
     * @param array          $list     List of excludes
     */
    protected function rsyncAddExcludeList(CommandBuilder $command, $list) {
        $rsyncFilter = $this->tempDir . '/.rsync-exclude';

        PhpUtility::filePutContents($rsyncFilter, implode("\n", $list));

        $command->addArgumentTemplate('--exclude-from=%s', $rsyncFilter);

        // cleanup rsync file
        $command->getExecutor()->addFinisherCallback(function () use ($rsyncFilter) {
            unlink($rsyncFilter);
        });
    }
}