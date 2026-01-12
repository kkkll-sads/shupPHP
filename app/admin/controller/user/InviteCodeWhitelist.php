<?php

namespace app\admin\controller\user;

use Throwable;
use think\facade\Db;
use app\admin\model\InviteCodeWhitelist as InviteCodeWhitelistModel;
use app\common\controller\Backend;
use think\exception\HttpResponseException;

class InviteCodeWhitelist extends Backend
{
    /**
     * @var InviteCodeWhitelistModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['code', 'remark'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new InviteCodeWhitelistModel();
    }

    /**
     * 列表
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $res = $this->model
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        $this->success('', [
            'list'   => $res->items(),
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 添加
     * @throws Throwable
     */
    public function add(): void
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            if (!$data) {
                $this->error('参数错误');
            }

            // 检查邀请码格式
            if (!preg_match('/^[a-zA-Z0-9]+$/', $data['code'])) {
                $this->error('邀请码格式不正确，仅允许字母和数字');
            }

            // 检查是否已存在
            $exists = $this->model->where('code', $data['code'])->find();
            if ($exists) {
                $this->error('该邀请码已存在于白名单中');
            }

            $data['admin_id'] = $this->auth->id;
            $result = $this->model->save($data);
            
            if ($result !== false) {
                $this->success('添加成功');
            } else {
                $this->error('添加失败');
            }
        }

        $this->error('参数错误');
    }

    /**
     * 编辑
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->find($id);
        if (!$row) {
            $this->error('记录不存在');
        }

        if ($this->request->isPost()) {
            $data = $this->request->post();
            if (!$data) {
                $this->error('参数错误');
            }

            // 验证邀请码
            if (isset($data['code']) && !preg_match('/^[a-zA-Z0-9]+$/', $data['code'])) {
                $this->error('邀请码格式不正确，仅允许字母和数字');
            }

            // 如果修改了邀请码，检查是否已存在
            if (isset($data['code']) && $data['code'] != $row->code) {
                $exists = $this->model->where('code', $data['code'])->where('id', '<>', $id)->find();
                if ($exists) {
                    $this->error('该邀请码已存在于白名单中');
                }
            }

            $result = $row->save($data);
            
            if ($result !== false) {
                $this->success('更新成功');
            } else {
                $this->error('更新失败');
            }
        }

        $this->success('', [
            'row' => $row
        ]);
    }

    /**
     * 删除
     * @throws Throwable
     */
    public function delete(): void
    {
        $pk = $this->model->getPk();
        $ids = $this->request->param($pk);
        
        if (!$ids) {
            $this->error('请选择要删除的记录');
        }

        $ids = is_array($ids) ? $ids : explode(',', $ids);
        $count = $this->model->whereIn($pk, $ids)->delete();
        
        if ($count) {
            $this->success('删除成功');
        } else {
            $this->error('删除失败');
        }
    }

    /**
     * 批量启用/禁用
     * @throws Throwable
     */
    public function batchStatus(): void
    {
        $ids = $this->request->post('ids');
        $status = $this->request->post('status');
        
        if (!$ids || !isset($status)) {
            $this->error('参数错误');
        }

        $ids = is_array($ids) ? $ids : explode(',', $ids);
        $status = (int)$status;
        
        if (!in_array($status, [0, 1])) {
            $this->error('状态值错误');
        }

        $count = $this->model->whereIn('id', $ids)->update(['status' => $status]);
        
        if ($count) {
            $this->success('操作成功');
        } else {
            $this->error('操作失败');
        }
    }

    /**
     * 导入邀请码（支持 TXT 和 Excel）
     * @throws Throwable
     */
    public function import(): void
    {
        $file = $this->request->file('file');
        if (!$file) {
            $this->error('请选择要导入的文件');
        }

        $fileExt = strtolower($file->getOriginalExtension());
        $allowedExts = ['txt', 'xlsx', 'xls', 'csv'];
        
        if (!in_array($fileExt, $allowedExts)) {
            $this->error('不支持的文件格式，仅支持：' . implode(', ', $allowedExts));
        }

        $codes = [];
        $errors = [];
        $successCount = 0;
        $skipCount = 0;

        try {
            $filePath = $file->getRealPath();
            if (!$filePath || !file_exists($filePath)) {
                $this->error('文件读取失败，请重新上传文件');
            }

            if ($fileExt == 'txt') {
                // 处理 TXT 文件
                $content = @file_get_contents($filePath);
                if ($content === false) {
                    $this->error('文件读取失败');
                }
                
                $lines = explode("\n", $content);
                foreach ($lines as $lineNum => $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    if (preg_match('/^[a-zA-Z0-9]+$/', $line)) {
                        $codes[] = $line;
                    } else {
                        $errors[] = "第 " . ($lineNum + 1) . " 行：邀请码格式不正确 ({$line})";
                    }
                }
            } else {
                // 处理 Excel/CSV 文件
                if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                    $codes = $this->parseExcelFile($filePath, $fileExt, $errors);
                } else {
                    if ($fileExt == 'csv') {
                        $codes = $this->parseCsvFile($filePath, $errors);
                    } else {
                        $this->error('请先安装 PhpSpreadsheet 库以支持 Excel 文件导入');
                    }
                }
            }

            if (empty($codes) && empty($errors)) {
                $this->error('文件中没有找到有效的邀请码');
            }
            
            if (empty($codes) && !empty($errors)) {
                $errorMsg = '没有有效邀请码。错误前5条：' . implode('；', array_slice($errors, 0, 5));
                $this->error($errorMsg);
            }

            // 去重
            $codes = array_unique($codes);
            
            Db::startTrans();
            try {
                foreach ($codes as $code) {
                    // 检查是否已存在
                    $exists = $this->model->where('code', $code)->find();
                    if ($exists) {
                        $skipCount++;
                        continue;
                    }

                    // 插入新记录
                    $this->model->create([
                        'code' => $code,
                        'status' => 1,
                        'admin_id' => $this->auth->id,
                        'remark' => '批量导入',
                    ]);
                    $successCount++;
                }
                
                Db::commit();
                
                $message = "导入完成！成功 {$successCount} 条，跳过 {$skipCount} 条重复";
                if (!empty($errors)) {
                    $message .= "，有部分格式错误";
                }
                
                $this->success($message, [
                    'success_count' => $successCount,
                    'skip_count' => $skipCount,
                    'error_count' => count($errors),
                    'errors' => array_slice($errors, 0, 10),
                ]);
            } catch (Throwable $e) {
                Db::rollback();
                $this->error('数据库操作失败：' . $e->getMessage());
            }
        } catch (Throwable $e) {
            $this->error('文件处理失败：' . $e->getMessage());
        }
    }

    private function parseExcelFile(string $filePath, string $ext, array &$errors): array
    {
        $codes = [];
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext == 'xlsx' ? 'Xlsx' : 'Xls');
            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();
            
            for ($row = 2; $row <= $highestRow; $row++) {
                $cellValue = $worksheet->getCell('A' . $row)->getValue();
                $code = trim((string)$cellValue);
                if (empty($code)) continue;
                
                if (preg_match('/^[a-zA-Z0-9]+$/', $code)) {
                    $codes[] = $code;
                } else {
                    $errors[] = "第 {$row} 行：格式不正确 ({$code})";
                }
            }
        } catch (\Exception $e) {
            $errors[] = 'Excel 读取失败：' . $e->getMessage();
        }
        return $codes;
    }

    private function parseCsvFile(string $filePath, array &$errors): array
    {
        $codes = [];
        $handle = @fopen($filePath, 'r');
        if ($handle) {
            $lineNum = 0;
            while (($data = fgetcsv($handle)) !== false) {
                $lineNum++;
                if ($lineNum == 1) continue; // Skip header
                
                $code = trim($data[0] ?? '');
                if (empty($code)) continue;
                
                if (preg_match('/^[a-zA-Z0-9]+$/', $code)) {
                    $codes[] = $code;
                } else {
                    $errors[] = "第 {$lineNum} 行：格式不正确";
                }
            }
            fclose($handle);
        }
        return $codes;
    }
}
