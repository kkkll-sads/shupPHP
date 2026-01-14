<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

class CheckItem30126 extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('check:item30126')
            ->setDescription('the check:item30126 command');
    }

    protected function execute(Input $input, Output $output)
    {
        // 指令输出
        $output->writeln('check:item30126');
    }
}
