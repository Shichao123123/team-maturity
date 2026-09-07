/* Print the visible comparison state, including charts and unscrolled table rows. */
function exportComparePdf(root) {
    var popup = window.open('', '_blank');
    if (!popup) {
        layui.layer.msg('请允许浏览器打开打印窗口后重试');
        return;
    }
    try {
        var copy = root.cloneNode(true);
        copy.querySelector('.compare-export-tools').remove();
        var originals = root.querySelectorAll('.compare-chart');
        copy.querySelectorAll('.compare-chart').forEach(function(node, index) {
            var chart = layui.echarts.getInstanceByDom(originals[index]);
            if (!chart) throw new Error('图表尚未加载完成');
            // Finish animations before taking the current legend/series state.
            chart.getZr().flush();
            var img = document.createElement('img');
            img.src = chart.getDataURL({type:'png', pixelRatio:3, backgroundColor:'#061f38', excludeComponents:['toolbox']});
            img.alt = node.parentNode.querySelector('h3').textContent;
            img.style.cssText = 'display:block;width:100%;height:auto;';
            node.replaceWith(img);
        });
        var title = document.createElement('h1');
        title.textContent = '企业横向对比分析';
        title.style.cssText = 'font-size:22px;margin:0 0 16px;color:#e9f5ff';
        copy.prepend(title);
        var doc = popup.document;
        doc.open();
        doc.write('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>企业横向对比分析</title></head><body></body></html>');
        doc.close();

        var css = doc.createElement('style');
        css.textContent = `
            @page{size:A3 landscape;margin:0}
            *{box-sizing:border-box}
            html,body{margin:0;min-height:100%;background:#031a32;color:#d9ecff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
            body{font:12px/1.5 "Microsoft YaHei",sans-serif}
            .compare-dashboard{width:100%;padding:12px;margin:0}
            h3{font-size:14px;margin:0 0 6px;color:#e2f0ff}
            .compare-note,.compare-chart-panel p{color:#94b5d2;font-size:11px;margin:4px 0 8px}
            .compare-summary{display:flex;gap:10px;margin-bottom:12px}
            .compare-summary article{flex:1;min-width:0;padding:10px;border:1px solid #245176;border-top:3px solid var(--company-color);background:#082641;break-inside:avoid}
            .compare-summary h3{overflow-wrap:anywhere}
            .compare-summary strong{font:26px Arial;color:var(--company-color)}
            .compare-summary p{margin:4px 0 0;color:#a1bfd8;font-size:11px}
            .compare-charts{display:block;margin-bottom:12px}
            .compare-print-row{display:table;table-layout:fixed;width:100%;border-spacing:8px 0;margin-bottom:10px;break-inside:avoid;page-break-inside:avoid}
            .compare-chart-panel{display:table-cell;width:50%;vertical-align:top;padding:10px;background:#06223e;border:1px solid #1b5a80}
            .compare-chart-panel img{width:100%!important;height:60mm!important;object-fit:contain}
            .compare-table-scroll{overflow:visible;max-height:none;margin-top:8px}
            .company-grid{width:100%;table-layout:fixed;border-collapse:collapse;font-size:11px}
            th,td{white-space:normal;overflow-wrap:anywhere;padding:7px;border:1px solid #245176;text-align:left}
            th{background:#103757}tr{break-inside:avoid;page-break-inside:avoid}
        `;
        var panels = Array.from(copy.querySelectorAll('.compare-chart-panel'));
        var chartGrid = copy.querySelector('.compare-charts');
        for (var i = 0; i < panels.length; i += 2) {
            var row = doc.createElement('div');
            row.className = 'compare-print-row';
            row.appendChild(panels[i]);
            if (panels[i + 1]) row.appendChild(panels[i + 1]);
            chartGrid.appendChild(row);
        }
        doc.head.appendChild(css);
        doc.body.appendChild(copy);
        var images = Array.from(doc.images);
        Promise.all(images.map(function(img) {
            return img.decode ? img.decode() : new Promise(function(resolve,reject){if(img.complete)resolve();else{img.onload=resolve;img.onerror=reject;}});
        })).then(function(){return doc.fonts ? doc.fonts.ready : null;}).then(function() {
            popup.focus();
            popup.print();
        }).catch(function() {
            popup.close();
            layui.layer.msg('图表生成失败，请稍后重试');
        });
    } catch (error) {
        popup.close();
        layui.layer.msg('导出失败，请等待图表加载完成后重试');
    }
}
