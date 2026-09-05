layui.use(['jquery','echarts'],function(){
    'use strict';
    var $=layui.jquery, ec=layui.echarts, root=document.querySelector('.screen'), org=Number(root.dataset.org), d=null, seq=0, selected=[], charts={};
    var colors={passive:'#ff555d',dependent:'#ffbe40',independent:'#2caaff',essential:'#39e99a'}, palette=['#19dfff','#329bff','#9867ff','#4cdeac','#ffc14a','#ff6a83'];
    ['pie','radar','weak','compare','history'].forEach(function(id){charts[id]=ec.init(document.getElementById(id));});
    function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
    function fmt(v){return v==null?'—':Number(v).toFixed(1);}
    function wrap(n){return n.replace(/(.{8})/g,'$1\n');}
    function empty(id,text){charts[id].clear();charts[id].setOption({title:{text:text||'当前范围暂无数据',left:'center',top:'middle',textStyle:{color:'#8aafcb',fontSize:13,fontWeight:'normal'}}});}
    function query(){var q={org_id:org};$('#filters').serializeArray().forEach(function(x){q[x.name]=x.value;});return q;}
    function download(action,ids){var q=query();if(ids)q.team_ids=ids.join(',');window.location.href='/TmNational/'+action+'?'+$.param(q);}
    function axis(type){return {type:type,axisLabel:{color:'#a9cbe5'},axisLine:{lineStyle:{color:'#286287'}},splitLine:{lineStyle:{color:'#124366'}}};}
    function national(names){var r=d.l1_radar_national||{indicators:[],values:[]};return names.map(function(n){var i=r.indicators.indexOf(n);return i<0?null:r.values[i];});}
    function rows(){var kw=$('#teamSearch').val().trim();return d.teams.filter(function(t){return !kw||t.team_name.indexOf(kw)>=0;});}
    function renderTable(){
        var visible=rows();
        $('#teamRows').html(visible.map(function(t){var id=t.team_id,checked=selected.indexOf(id)>=0;
            return '<tr class="'+(checked?'checked':'')+'"><td><input type="checkbox" data-choice="'+id+'" '+(checked?'checked':'')+' aria-label="选择'+esc(t.team_name)+'"></td><td>'+t.rank_no+'</td><td><button data-detail="'+id+'">'+esc(t.team_name)+'</button></td><td>'+fmt(t.team_score)+'</td><td><span class="tag '+(colors[t.level_code]?t.level_code:'')+'">'+esc(t.level_name)+'</span></td><td>'+fmt(t.leader_score)+'</td><td>'+fmt(t.member_score)+'</td><td>'+esc((t.strengths||[]).join('、')||'—')+'</td><td>'+esc((t.weak_top||[]).join('、')||'—')+'</td><td>'+esc((t.problems||[]).slice(0,2).join('；')||'暂无问题记录')+'</td><td><button data-detail="'+id+'">查看详情</button><button data-pdf="'+id+'">导出 PDF</button></td></tr>';
        }).join('')||'<tr><td colspan="11" class="empty">当前筛选范围暂无班组</td></tr>');
        $('#selected').text('已选择 '+selected.length+' 个班组');$('#batchReport').prop('disabled',!selected.length);
        var picked=visible.filter(function(t){return selected.indexOf(t.team_id)>=0;}).length;
        $('#selectAll').prop('checked',!!visible.length&&picked===visible.length).prop('indeterminate',picked>0&&picked<visible.length);
    }
    function compare(){
        var ts=d.teams.filter(function(t){return selected.indexOf(t.team_id)>=0;});
        if(!ts.length){empty('compare','请在班组表中勾选需要对比的班组');return;}
        var names=[];ts.forEach(function(t){(t.indicator_rates||[]).forEach(function(r){if(names.indexOf(r.name)<0)names.push(r.name);});});
        if(!names.length){empty('compare','所选班组暂无一级指标数据');return;}
        var series=ts.map(function(t,i){return {name:t.team_name,type:'bar',barMaxWidth:27,itemStyle:{color:palette[i%palette.length]},data:names.map(function(n){var r=t.indicator_rates.filter(function(r){return r.name===n;})[0];return r?r.score:null;})};});
        series.push({name:'全国行业均值',type:'line',symbol:'none',connectNulls:false,lineStyle:{type:'dashed',color:'#ffab35',width:2},itemStyle:{color:'#ffab35'},data:national(names)});
        charts.compare.setOption({animation:false,tooltip:{trigger:'axis',confine:true},legend:{type:'scroll',top:0,textStyle:{color:'#c8e6fb'}},grid:{left:42,right:18,top:40,bottom:52},xAxis:Object.assign(axis('category'),{data:names,axisLabel:{color:'#b9dafa',fontSize:10,interval:0,formatter:wrap}}),yAxis:Object.assign(axis('value'),{max:100}),dataZoom:names.length>7?[{type:'inside',start:0,end:Math.min(100,700/names.length)}]:[],series:series},true);
    }
    function history(){
        var t=d.teams.filter(function(t){return String(t.team_id)===$('#historyTeam').val();})[0];
        if(!t||!t.history.length){empty('history','暂无历史测评记录');return;}
        var hs=t.history, names={passive:'被动型',dependent:'依赖型',independent:'自主型',essential:'本质安全型'};
        charts.history.setOption({tooltip:{trigger:'axis',confine:true,formatter:function(ps){var h=hs[ps[0].dataIndex];return esc(h.batch_name)+'<br>'+esc(h.scored_at)+'<br>'+fmt(h.team_score)+' 分 · '+esc(names[h.level_code]||h.level_code);}},grid:{left:40,right:20,top:25,bottom:40},xAxis:Object.assign(axis('category'),{data:hs.map(function(h){return h.scored_at.slice(0,10);})}),yAxis:Object.assign(axis('value'),{min:0,max:100}),series:[{type:'line',symbolSize:9,lineStyle:{color:'#22daff'},itemStyle:{color:'#22daff'},areaStyle:{color:'#17c8ff',opacity:.12},data:hs.map(function(h){return h.team_score;})}]},true);
    }
    function render(){
        $('#pageTitle').text(d.org.name+'安全成熟度详情');$('#orgMeta').text([d.org.industry,d.org.province,d.org.city].filter(Boolean).join(' · '));
        $('#source').text(d.is_demo?'演示企业 · 示例诊断数据':'');$('#score').text(d.summary.score_text);$('#level').text(d.summary.level_name);$('#total').text(d.team_total);$('#completed').text(d.completed_count);$('#completion').text(d.completion_rate+'%');$('#rank').text(d.rank?d.rank+' / '+d.rank_total:'—');$('#feature').text(d.feature_desc||'暂无等级特征').attr('title',d.feature_desc||'');$('.steps span').removeClass('active').filter(function(){return this.dataset.level===d.summary.level_code;}).addClass('active');
        var total=d.teams.length;
        if(!total)empty('pie');else charts.pie.setOption({tooltip:{trigger:'item',confine:true},title:{text:String(total),subtext:'当前班组数',left:'27%',top:'40%',textAlign:'center',textStyle:{color:'#fff',fontSize:25},subtextStyle:{color:'#b9d8ee',fontSize:12}},legend:{orient:'vertical',right:0,top:'middle',itemWidth:10,itemGap:17,textStyle:{color:'#d3e8fa',fontSize:11},formatter:function(n){var l=d.levels.filter(function(l){return l.level_name===n;})[0];return n+'  '+l.count+' ('+(100*l.count/total).toFixed(1)+'%)';}},series:[{type:'pie',radius:['43%','73%'],center:['28%','52%'],label:{show:false},data:d.levels.map(function(l){return {name:l.level_name,value:l.count,itemStyle:{color:colors[l.level_code]}};})}]},true);
        var r=d.l1_radar_company||{indicators:[],values:[]};
        if(!r.indicators.length)empty('radar');else charts.radar.setOption({tooltip:{confine:true},legend:{top:0,right:0,textStyle:{color:'#c7e1f7'}},color:['#25dcff','#ffab38'],radar:{radius:'59%',center:['50%','56%'],name:{color:'#cee5ff',fontSize:11,formatter:wrap},indicator:r.indicators.map(function(n){return {name:n,max:100};}),splitArea:{areaStyle:{color:['#01213b','#032d4c']}},splitLine:{lineStyle:{color:'#16658c'}},axisLine:{lineStyle:{color:'#195e83'}}},series:[{type:'radar',data:[{name:'本企业',value:r.values,areaStyle:{opacity:.2}},{name:'全国行业均值',value:national(r.indicators),lineStyle:{type:'dashed'},areaStyle:{opacity:0}}]}]},true);
        if(!d.weak_top5.length)empty('weak','暂无可统计的二级短板');else charts.weak.setOption({tooltip:{trigger:'axis',confine:true,valueFormatter:function(v){return v+'%';}},grid:{left:145,right:40,top:16,bottom:25},xAxis:Object.assign(axis('value'),{max:100}),yAxis:{type:'category',inverse:true,data:d.weak_top5.map(function(w){return w.indicator_name;}),axisLabel:{color:'#d7eaff',fontSize:11,formatter:wrap},axisTick:{show:false},axisLine:{show:false}},series:[{type:'bar',barMaxWidth:17,showBackground:true,backgroundStyle:{color:'#163854'},label:{show:true,position:'right',color:'#dff5ff',formatter:'{c}%'},data:d.weak_top5.map(function(w,i){return {value:w.loss_rate,itemStyle:{color:i===0?'#ff555d':i<3?'#ff903d':'#ffcb55'}};})}]},true);
        $('#questions').html(d.questions.map(function(q,i){return '<div class="question-item"><span>'+(i+1)+'. '+esc(q.title)+'</span><small>'+q.count+' 次 · '+q.team_count+' 个班组</small></div>';}).join('')||'<p class="empty">当前范围暂无高频问题</p>');
        $('#historyTeam').html(d.teams.map(function(t){return '<option value="'+t.team_id+'">'+esc(t.team_name)+'</option>';}).join(''));
        renderTable();compare();history();resize();
    }
    function load(){var q=query();if(q.date_from&&q.date_to&&q.date_from>q.date_to){$('#status').text('开始日期不能晚于结束日期');return;}var n=++seq;$('#status').text('正在加载…');$('#report,#questionsExport,#batchReport').prop('disabled',true);
        $.getJSON('/TmNational/companyData',q).done(function(res){if(n!==seq)return;if(!res.success){$('#status').text(res.msg||'加载失败');return;}d=res.data;selected=d.teams.slice(0,3).map(function(t){return t.team_id;});render();$('#report,#questionsExport').prop('disabled',false);$('#status').text('已更新');}).fail(function(){if(n===seq)$('#status').text('加载失败，请重试');});}
    $('#filters').on('submit',function(e){e.preventDefault();load();});$('#filters select,#filters input').on('change',load);
    $('#teamSearch').on('input',function(){if(d)renderTable();});
    $('#teamRows').on('change','[data-choice]',function(){var id=Number(this.dataset.choice);selected=selected.filter(function(x){return x!==id;});if(this.checked)selected.push(id);renderTable();compare();});
    $('#selectAll').on('change',function(){if(!d)return;var yes=this.checked;rows().forEach(function(t){selected=selected.filter(function(id){return id!==t.team_id;});if(yes)selected.push(t.team_id);});renderTable();compare();});
    $('#historyTeam').on('change',history);$('#report').on('click',function(){download('exportCompany');});$('#batchReport').on('click',function(){if(selected.length)download('exportCompany',selected);});$('#questionsExport').on('click',function(){download('exportQuestions');});
    $('#teamRows').on('click','[data-pdf]',function(){download('exportCompany',[Number(this.dataset.pdf)]);});
    $('#teamRows').on('click','[data-detail]',function(){var id=Number(this.dataset.detail),t=d.teams.filter(function(t){return t.team_id===id;})[0];if(!t)return;
        $('#detailTitle').text(t.team_name+' · 测评诊断');
        $('#detailBody').html('<p>'+fmt(t.team_score)+' 分 · '+esc(t.level_name)+' · '+esc(t.scored_at)+(d.is_demo?' · 演示数据':'')+'</p><h3>一级指标得分率</h3><table><thead><tr><th>指标</th><th>得分率</th><th>失分率</th></tr></thead><tbody>'+t.indicator_rates.map(function(r){return '<tr><td>'+esc(r.name)+'</td><td>'+fmt(r.score)+'%</td><td>'+fmt(100-r.score)+'%</td></tr>';}).join('')+'</tbody></table><h3>暴露问题</h3>'+((t.problems||[]).map(function(p){return '<p>'+esc(p)+'</p>';}).join('')||'<p>暂无问题记录</p>')+'<h3>改进建议</h3>'+((t.measures||[]).map(function(m){return '<p>'+esc(m.title)+'：'+esc(m.content)+'</p>';}).join('')||'<p>尚未配置对应改进建议</p>'));
        document.getElementById('detailDialog').showModal();
    });
    $('#closeDetail').on('click',function(){document.getElementById('detailDialog').close();});
    $('#fullscreen').on('click',function(){var p=document.fullscreenElement?document.exitFullscreen():document.documentElement.requestFullscreen();if(p&&p.catch)p.catch(function(){$('#status').text('当前浏览器不允许全屏，可按 F11');});});
    if(root.dataset.scoped==='1'){$('#back').hide();$('#logout').prop('hidden',false);}
    function resize(){Object.keys(charts).forEach(function(k){charts[k].resize();});}
    window.addEventListener('resize',resize);if(window.ResizeObserver)new ResizeObserver(resize).observe(root);
    load();
});
