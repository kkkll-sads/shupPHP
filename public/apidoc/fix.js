// Apidoc前端修复脚本
// 修复apiDetail请求缺少path参数的问题

(function() {
  'use strict';

  console.log('Apidoc修复脚本已加载');

  // 监听所有点击事件，尝试捕获菜单点击
  document.addEventListener('click', function(e) {
    const target = e.target;

    // 查找可能的菜单项元素 (向上查找)
    let menuItem = target;
    let menuKey = null;

    // 查找包含menuKey数据的元素
    while (menuItem && menuItem !== document.body) {
      // 尝试多种方式获取menuKey
      menuKey = menuKey || menuItem.getAttribute('data-menu-key');
      menuKey = menuKey || menuItem.getAttribute('data-key');
      menuKey = menuKey || menuItem.getAttribute('menu-key');

      // 检查元素的内容或子元素
      if (!menuKey && menuItem.textContent) {
        // 如果点击的是文本节点，查找父元素的属性
        const parent = menuItem.parentElement;
        if (parent) {
          menuKey = menuKey || parent.getAttribute('data-menu-key');
          menuKey = menuKey || parent.getAttribute('data-key');
        }
      }

      // 如果找到menuKey，停止查找
      if (menuKey) break;

      menuItem = menuItem.parentElement;
    }

    // 如果没找到，尝试从事件路径中查找
    if (!menuKey && e.path) {
      for (let i = 0; i < e.path.length; i++) {
        const el = e.path[i];
        if (el && el.getAttribute) {
          menuKey = menuKey || el.getAttribute('data-menu-key');
          menuKey = menuKey || el.getAttribute('data-key');
          if (menuKey) break;
        }
      }
    }

    if (menuKey) {
      // 保存到存储中
      localStorage.setItem('apidoc_last_menu_key', menuKey);
      sessionStorage.setItem('apidoc_last_menu_key', menuKey);
      console.log('检测到菜单点击，保存menuKey:', menuKey);
    }
  }, true);

  // 拦截XMLHttpRequest
  const originalOpen = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function(method, url) {
    if (typeof url === 'string' && url.includes('apiDetail')) {
      const urlObj = new URL(url, window.location.origin);

      // 如果缺少path参数，尝试添加
      if (!urlObj.searchParams.get('path')) {
        let path = null;

        // 尝试从各种地方获取path
        path = path || localStorage.getItem('apidoc_last_menu_key');
        path = path || sessionStorage.getItem('apidoc_last_menu_key');

        // 尝试从URL参数获取
        const urlParams = new URLSearchParams(window.location.search);
        path = path || urlParams.get('path');

        // 尝试从hash获取
        if (window.location.hash) {
          const hashParams = new URLSearchParams(window.location.hash.substring(1));
          path = path || hashParams.get('path');
        }

        if (path) {
          urlObj.searchParams.set('path', path);
          url = urlObj.toString();
          console.log('修复apiDetail请求，添加path参数:', path);
        } else {
          console.warn('无法获取path参数，apiDetail请求可能失败');
        }
      }
    }

    return originalOpen.call(this, method, url);
  };

  // 拦截fetch请求
  if (window.fetch) {
    const originalFetch = window.fetch;
    window.fetch = function(input, init) {
      let url = input;

      if (typeof url === 'string' && url.includes('apiDetail')) {
        const urlObj = new URL(url, window.location.origin);

        if (!urlObj.searchParams.get('path')) {
          let path = null;

          path = path || localStorage.getItem('apidoc_last_menu_key');
          path = path || sessionStorage.getItem('apidoc_last_menu_key');

          const urlParams = new URLSearchParams(window.location.search);
          path = path || urlParams.get('path');

          if (window.location.hash) {
            const hashParams = new URLSearchParams(window.location.hash.substring(1));
            path = path || hashParams.get('path');
          }

          if (path) {
            urlObj.searchParams.set('path', path);
            url = urlObj.toString();
            if (init) {
              input = url;
            } else {
              input = url;
            }
            console.log('修复fetch apiDetail请求，添加path参数:', path);
          }
        }
      }

      return originalFetch.call(this, input, init);
    };
  }

  // 页面加载完成后，尝试从URL参数中提取path并保存
  window.addEventListener('load', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const pathFromUrl = urlParams.get('path');

    if (pathFromUrl) {
      localStorage.setItem('apidoc_last_menu_key', pathFromUrl);
      sessionStorage.setItem('apidoc_last_menu_key', pathFromUrl);
      console.log('从URL参数保存path:', pathFromUrl);
    }

    // 监听hashchange事件
    window.addEventListener('hashchange', function() {
      const hashParams = new URLSearchParams(window.location.hash.substring(1));
      const pathFromHash = hashParams.get('path');

      if (pathFromHash) {
        localStorage.setItem('apidoc_last_menu_key', pathFromHash);
        sessionStorage.setItem('apidoc_last_menu_key', pathFromHash);
        console.log('从hash保存path:', pathFromHash);
      }
    });
  });

  console.log('Apidoc修复脚本初始化完成');
})();
