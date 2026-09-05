/* 纯浏览器演示数据：不调用任何写入接口，所有 ID 为负数。 */
window.nationalDemoData = function(f) {
    var places=[['河北省','唐山市',118.18,39.63],['山东省','济南市',117,36.67],['辽宁省','鞍山市',123,41.11],['江苏省','南京市',118.8,32.06],['湖北省','武汉市',114.3,30.59],['湖南省','长沙市',112.98,28.2],['广东省','湛江市',110.36,21.27],['四川省','攀枝花市',101.72,26.58],['云南省','昆明市',102.71,25.04],['内蒙古自治区','包头市',109.84,40.66],['甘肃省','嘉峪关市',98.28,39.79],['山西省','太原市',112.55,37.87],['河南省','安阳市',114.39,36.1],['福建省','三明市',117.64,26.26],['安徽省','马鞍山市',118.51,31.67],['广西壮族自治区','柳州市',109.42,24.33],['江西省','新余市',114.92,27.82],['贵州省','贵阳市',106.63,26.65]];
    var industries=['钢铁','有色','焦化'],codes=['passive','dependent','independent','essential'],labels=['被动型','依赖型','自立型','本质安全型'];
    var indicators=['班组安全管理基础','班组安全教育培训','双重预防机制管理','班组安全活动','班组现场作业管理','事故管理及应急处置'];
    var weakNames=['安全责任落实','隐患排查治理','岗位风险辨识','应急演练评估','作业许可管理','设备设施维护','教育培训覆盖','班前安全交底','劳动防护用品','事故经验反馈'];
    var companies=[];
    for(var i=0;i<36;i++){
        var p=places[i%places.length],score=+(62+(i*17%350)/10).toFixed(1),level=score>=90?3:score>=80?2:score>=70?1:0;
        var rates=indicators.map(function(n,j){return {name:n,score:+Math.min(99,Math.max(40,score+(i*7+j*11)%23-11)).toFixed(1)};});
        var sorted=rates.slice().sort(function(a,b){return b.score-a.score;}),teams=8+i*7%43;
        companies.push({org_id:-(i+1),name:'【演示】'+p[1].replace('市','')+['宏远','华盛'][Math.floor(i/18)]+'冶金公司',province:p[0],city:p[1],industry:industries[i%3],score:score,score_text:score.toFixed(1),level_code:codes[level],level_name:labels[level],measured_team_cnt:teams,sample_cnt:teams*(18+i%12),eval_cnt:1+i%4,scored_at:'2026-08-'+String(1+i%28).padStart(2,'0')+' 10:00:00',indicator_rates:rates,strengths:sorted.slice(0,3).map(function(x){return x.name;}),weaknesses:sorted.slice(-3).reverse().map(function(x){return x.name;}),value:[p[2],p[3],score]});
    }
    companies=companies.filter(function(c){return (!f.industry||c.industry===f.industry)&&(!f.province||c.province===f.province)&&(!f.level_code||c.level_code===f.level_code)&&(!f.keyword||c.name.indexOf(f.keyword)>=0)&&(!f.date_from||c.scored_at.slice(0,10)>=f.date_from)&&(!f.date_to||c.scored_at.slice(0,10)<=f.date_to);});
    var levels=codes.map(function(code,i){var count=companies.filter(function(c){return c.level_code===code;}).length,pct=companies.length?+(100*count/companies.length).toFixed(1):0;return {level_code:code,level_name:labels[i],count:count,percent:pct,percent_text:pct+'%'};});
    function sum(field){return companies.reduce(function(s,c){return s+c[field];},0);}
    var avg=companies.length?sum('score')/companies.length:0;
    return {demo:true,companies:companies,map_points:companies,levels:levels,kpi:{company_cnt:companies.length,scored_cnt:companies.length,team_cnt:sum('measured_team_cnt'),paper_cnt:sum('sample_cnt'),eval_cnt:sum('eval_cnt'),avg_score_text:companies.length?avg.toFixed(1):'—'},
        l1_radar:{indicators:companies.length?indicators:[],values:companies.length?indicators.map(function(n,j){return +(companies.reduce(function(s,c){return s+c.indicator_rates[j].score;},0)/companies.length).toFixed(1);}):[]},
        weak_top10:companies.length?weakNames.map(function(n,j){return {indicator_id:-(j+1),indicator_name:n,loss_rate:+Math.max(1,100-avg+10-j*1.4).toFixed(1),questions:[{title:'【演示】'+n+'是否按计划落实并保留记录？',count:Math.max(1,companies.length*(12-j))},{title:'【演示】发现的问题是否完成整改复核？',count:Math.max(1,companies.length*(10-j))}]};}):[],tip:'演示数据 · 虚构企业与测评结果，仅供界面展示；演示日期为 2026 年 8 月，不纳入真实统计。'};
};
