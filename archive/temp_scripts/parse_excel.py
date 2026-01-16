#!/usr/bin/env python3
import zipfile
import xml.etree.ElementTree as ET
import sys

def read_xlsx(filename):
    """Parse xlsx file without openpyxl"""
    try:
        with zipfile.ZipFile(filename, 'r') as zip_ref:
            # Read shared strings
            shared_strings = []
            try:
                with zip_ref.open('xl/sharedStrings.xml') as f:
                    tree = ET.parse(f)
                    root = tree.getroot()
                    ns = {'ns': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
                    for si in root.findall('.//ns:si', ns):
                        t = si.find('.//ns:t', ns)
                        if t is not None:
                            shared_strings.append(t.text or '')
            except:
                pass
            
            # Read sheet data
            with zip_ref.open('xl/worksheets/sheet1.xml') as f:
                tree = ET.parse(f)
                root = tree.getroot()
                ns = {'ns': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
                
                rows_data = []
                for row in root.findall('.//ns:row', ns):
                    row_data = []
                    for cell in row.findall('.//ns:c', ns):
                        cell_type = cell.get('t')
                        v = cell.find('.//ns:v', ns)
                        
                        if v is not None:
                            if cell_type == 's':  # String (shared)
                                idx = int(v.text)
                                row_data.append(shared_strings[idx] if idx < len(shared_strings) else '')
                            else:
                                row_data.append(v.text or '')
                        else:
                            row_data.append('')
                    rows_data.append(row_data)
                
                return rows_data
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        return []

if __name__ == '__main__':
    filename = sys.argv[1] if len(sys.argv) > 1 else '/www/wwwroot/23.248.226.82/代理信息登记.xlsx'
    data = read_xlsx(filename)
    
    if data:
        # Print header
        if len(data) > 0:
            print("表头:", data[0])
            print("\n数据行:")
            for i, row in enumerate(data[1:], start=2):
                print(f"第{i}行:", row)
    else:
        print("无法读取数据")
