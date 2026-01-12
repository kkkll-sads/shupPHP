<?php

namespace app\admin\controller\user;

use Throwable;
use think\facade\Db;
use app\admin\model\MobileWhitelist as MobileWhitelistModel;
use app\common\controller\Backend;
use think\exception\HttpResponseException;

class MobileWhitelist extends Backend
{
    /**
     * @var MobileWhitelistModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['mobile', 'remark'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new MobileWhitelistModel();
    }

    /**
     * 列表
     * @throws Throwable
     */
    public function index(): void
    {
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

            // 验证手机号格式
            if (!preg_match('/^1[3-9]\d{9}$/', $data['mobile'])) {
                $this->error('手机号格式不正确');
            }

            // 检查是否已存在
            $exists = $this->model->where('mobile', $data['mobile'])->find();
            if ($exists) {
                $this->error('该手机号已存在于白名单中');
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

            // 验证手机号格式
            if (isset($data['mobile']) && !preg_match('/^1[3-9]\d{9}$/', $data['mobile'])) {
                $this->error('手机号格式不正确');
            }

            // 如果修改了手机号，检查是否已存在
            if (isset($data['mobile']) && $data['mobile'] != $row->mobile) {
                $exists = $this->model->where('mobile', $data['mobile'])->where('id', '<>', $id)->find();
                if ($exists) {
                    $this->error('该手机号已存在于白名单中');
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
     * 导入手机号（支持 TXT 和 Excel）
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

        $mobiles = [];
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
                    $this->error('文件读取失败：' . error_get_last()['message'] ?? '未知错误');
                }
                
                $lines = explode("\n", $content);
                if (empty($lines)) {
                    $this->error('文件内容为空');
                }
                
                foreach ($lines as $lineNum => $line) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }
                    
                    // 验证手机号格式
                    if (preg_match('/^1[3-9]\d{9}$/', $line)) {
                        $mobiles[] = $line;
                    } else {
                        $errors[] = "第 " . ($lineNum + 1) . " 行：手机号格式不正确 ({$line})";
                    }
                }
            } else {
                // 处理 Excel/CSV 文件
                // 先尝试使用 PhpSpreadsheet，如果没有安装则使用简单方法
                if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                    $mobiles = $this->parseExcelFile($filePath, $fileExt, $errors);
                } else {
                    // 如果没有 PhpSpreadsheet，尝试使用 CSV 方式读取
                    if ($fileExt == 'csv') {
                        $mobiles = $this->parseCsvFile($filePath, $errors);
                    } else {
                        $this->error('请先安装 PhpSpreadsheet 库以支持 Excel 文件导入。可以使用命令：composer require phpoffice/phpspreadsheet');
                    }
                }
            }

            if (empty($mobiles) && empty($errors)) {
                $this->error('文件中没有找到有效的手机号，请检查文件格式是否正确');
            }
            
            if (empty($mobiles) && !empty($errors)) {
                $errorMsg = '文件中没有找到有效的手机号。错误详情：' . implode('；', array_slice($errors, 0, 5));
                if (count($errors) > 5) {
                    $errorMsg .= '（还有 ' . (count($errors) - 5) . ' 个错误）';
                }
                $this->error($errorMsg);
            }

            // 去重
            $mobiles = array_unique($mobiles);
            
            Db::startTrans();
            try {
                foreach ($mobiles as $mobile) {
                    // 检查是否已存在
                    $exists = $this->model->where('mobile', $mobile)->find();
                    if ($exists) {
                        $skipCount++;
                        continue;
                    }

                    // 插入新记录
                    $this->model->create([
                        'mobile' => $mobile,
                        'status' => 1,
                        'admin_id' => $this->auth->id,
                        'remark' => '批量导入',
                    ]);
                    $successCount++;
                }
                
                Db::commit();
                
                $message = "导入完成！成功导入 {$successCount} 条，跳过 {$skipCount} 条重复记录";
                if (!empty($errors)) {
                    $message .= "，错误 " . count($errors) . " 条";
                }
                
                $this->success($message, [
                    'success_count' => $successCount,
                    'skip_count' => $skipCount,
                    'error_count' => count($errors),
                    'errors' => array_slice($errors, 0, 10), // 只返回前10个错误
                ]);
            } catch (HttpResponseException $e) {
                throw $e;
            } catch (Throwable $e) {
                Db::rollback();
                $errorMsg = '数据库操作失败：' . $e->getMessage();
                if ($e->getCode()) {
                    $errorMsg .= '（错误代码：' . $e->getCode() . '）';
                }
                $this->error($errorMsg);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $errorMsg = '文件处理失败：' . $e->getMessage();
            if ($e->getFile() && $e->getLine()) {
                $errorMsg .= '（文件：' . basename($e->getFile()) . '，行号：' . $e->getLine() . '）';
            }
            $this->error($errorMsg);
        }
    }

    /**
     * 解析 Excel 文件
     */
    private function parseExcelFile(string $filePath, string $ext, array &$errors): array
    {
        $mobiles = [];
        
        if (!file_exists($filePath)) {
            $errors[] = 'Excel 文件不存在';
            return $mobiles;
        }
        
        if (!is_readable($filePath)) {
            $errors[] = 'Excel 文件无法读取，请检查文件权限';
            return $mobiles;
        }
        
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader(
                $ext == 'xlsx' ? 'Xlsx' : 'Xls'
            );
            
            if (!$reader->canRead($filePath)) {
                $errors[] = '无法读取 Excel 文件，文件可能已损坏或格式不正确';
                return $mobiles;
            }
            
            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $highestRow = $worksheet->getHighestRow();
            
            if ($highestRow < 2) {
                $errors[] = 'Excel 文件中没有数据（至少需要2行：1行标题+1行数据）';
                return $mobiles;
            }
            
            // 从第2行开始读取（假设第一行是标题）
            for ($row = 2; $row <= $highestRow; $row++) {
                $cellValue = $worksheet->getCell('A' . $row)->getValue();
                $mobile = trim((string)$cellValue);
                
                if (empty($mobile)) {
                    continue;
                }
                
                // 如果是数字格式，可能需要转换
                if (is_numeric($mobile) && strlen($mobile) == 11) {
                    $mobile = (string)$mobile;
                }
                
                if (preg_match('/^1[3-9]\d{9}$/', $mobile)) {
                    $mobiles[] = $mobile;
                } else {
                    $errors[] = "第 {$row} 行：手机号格式不正确 ({$mobile})";
                }
            }
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            $errors[] = 'Excel 文件读取失败：' . $e->getMessage();
        } catch (\Exception $e) {
            $errors[] = 'Excel 文件解析失败：' . $e->getMessage() . '（文件：' . basename($filePath) . '）';
        }
        
        return $mobiles;
    }

    /**
     * 解析 CSV 文件
     */
    private function parseCsvFile(string $filePath, array &$errors): array
    {
        $mobiles = [];
        
        if (!file_exists($filePath)) {
            $errors[] = 'CSV 文件不存在';
            return $mobiles;
        }
        
        if (!is_readable($filePath)) {
            $errors[] = 'CSV 文件无法读取，请检查文件权限';
            return $mobiles;
        }
        
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            $errors[] = 'CSV 文件打开失败：' . (error_get_last()['message'] ?? '未知错误');
            return $mobiles;
        }
        
        $lineNum = 0;
        while (($data = fgetcsv($handle)) !== false) {
            $lineNum++;
            // 跳过第一行（标题行）
            if ($lineNum == 1) {
                continue;
            }
            
            $mobile = trim($data[0] ?? '');
            if (empty($mobile)) {
                continue;
            }
            
            if (preg_match('/^1[3-9]\d{9}$/', $mobile)) {
                $mobiles[] = $mobile;
            } else {
                $errors[] = "第 {$lineNum} 行：手机号格式不正确 ({$mobile})";
            }
        }
        fclose($handle);
        
        return $mobiles;
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
}

