<?php
namespace UCASSer\Campus;

/** Reviewed public list pages and documented account identities; no article text is bundled. */
class OfficialSourceCatalog
{
    public const WEB = [
        ['学校通知公告', 'https://www.ucass.edu.cn/index/tzgg.htm'],
        ['马克思主义学院通知公告', 'https://marxism.ucass.edu.cn/tzgg.htm'],
        ['文学院学院公告', 'https://wenxue.ucass.edu.cn/xwgg/xygg.htm'],
        ['外国语学院通知公告', 'https://sfl.ucass.edu.cn/tzgg.htm'],
        ['哲学院院内通知', 'https://phil.ucass.edu.cn/xwtz/yntz.htm'],
        ['哲学院会议讲座', 'https://phil.ucass.edu.cn/xwtz/hyjz1.htm'],
        ['历史学院新闻公告', 'https://history.ucass.edu.cn/xwzx/xwgg.htm'],
        ['经济学院通知公告', 'https://se.ucass.edu.cn/tzgg.htm'],
        ['商学院教学通知及讲座', 'https://bs.ucass.edu.cn/jxyky/zyxw/tzgg.htm'],
        ['应用经济学院公告', 'https://fae.ucass.edu.cn/index/gg.htm'],
        ['法学院通知公告', 'https://law.ucass.edu.cn/xwzhong_xin/tzgg.htm'],
        ['政府管理学院通知公告', 'https://sg.ucass.edu.cn/jxgl/tzgg.htm'],
        ['社会与民族学院通知公告', 'https://shymzxy.ucass.edu.cn/xwzx/tzgg1.htm'],
        ['新闻传播学院通知公告', 'https://media.ucass.edu.cn/xwgg/tzgg.htm'],
        ['全球与区域国别学院通知公告', 'https://sipe.ucass.edu.cn/xwzx/tzgg.htm'],
        ['教务处通知公告', 'https://jw.ucass.edu.cn/tzgg.htm'],
        ['招生网通知公告', 'https://skdzs.ucass.edu.cn/index/tzgg.htm'],
        ['学生工作部通知公告', 'https://xgb.ucass.edu.cn/tzgg.htm'],
        ['校团委通知公告', 'https://youth.ucass.edu.cn/tzgg.htm'],
        ['科研处通知公告', 'https://ky.ucass.edu.cn/tzgg.htm'],
        ['规划与评估处通知公告', 'https://ghypgc.ucass.edu.cn/tzgg.htm'],
        ['网络中心通知公告', 'https://nc.ucass.edu.cn/tzgg.htm'],
        ['研究生教育管理部通知公告', 'https://yjsjy.ucass.edu.cn/tzgg.htm'],
        ['保卫处通知公告', 'https://bwc.ucass.edu.cn/tzgg.htm'],
        ['迎新网通知公告', 'https://welcome.ucass.edu.cn/tzgg.htm'],
        ['国际教育学院学院公告', 'https://sged.ucass.edu.cn/xwgg/xygg.htm'],
        ['终身教育学院通知公告', 'https://sle.ucass.edu.cn/xwgg1/tzgg.htm'],
        ['国际交流与合作处海外学分项目', 'https://intl.ucass.edu.cn/cg_j_xx1/hwxfxm.htm'],
        ['国际交流与合作处外专讲座', 'https://intl.ucass.edu.cn/wzyz1/tzgg.htm'],
        ['国际交流与合作处国际学生通知', 'https://intl.ucass.edu.cn/lxskd1/tzgg.htm'],
        ['校工会通知公告', 'https://gh.ucass.edu.cn/tzgg.htm'],
        ['继续教育管理中心通知公告', 'https://cea.ucass.edu.cn/tzgg.htm'],
        ['财务处通知公告', 'https://cwc.ucass.edu.cn/tzgg.htm'],
        ['人事处通知公告', 'https://rsc.ucass.edu.cn/tzgg1.htm'],
        ['人事处人才招聘', 'https://rsc.ucass.edu.cn/rczp1.htm'],
        ['招投标和采购中心采购意向', 'https://zc.ucass.edu.cn/cgyxgk.htm'],
        ['招投标和采购中心货物公告', 'https://zc.ucass.edu.cn/cggg/hw.htm'],
        ['招投标和采购中心服务公告', 'https://zc.ucass.edu.cn/cggg/fw.htm'],
        ['招投标和采购中心工程公告', 'https://zc.ucass.edu.cn/cggg/gc.htm'],
        ['税务硕士教育中心通知公告', 'https://mt.ucass.edu.cn/tzgg.htm'],
    ];

    // This official notices page is rendered from a public JSON API, not Visual SiteBuilder.
    public const EMPLOYMENT = [
        ['就业指导中心通知公告', UcassEmploymentAdapter::LIST_URL],
    ];

    // These identities have official-page evidence, but no authorized feed has been connected yet.
    public const WECHAT = [
        ['中国社会科学院大学', 'https://www.ucass.edu.cn/'],
        ['青春社科大', 'https://youth.ucass.edu.cn/info/1006/3154.htm'],
        ['UCASSInternational', 'https://jw.ucass.edu.cn/__local/1/A0/45/BF3AE6E5714AEDFB203961A4CC4_0C4B7FAF_7BFC12.pdf'],
        ['中国社会科学院大学文学院', 'https://wenxue.ucass.edu.cn/info/1029/2068.htm'],
        ['社科大社会与民族学院', 'https://xmjh.ucass.edu.cn/info/1036/1389.htm'],
        ['社科大哲学院', 'https://phil.ucass.edu.cn/info/1008/1832.htm'],
        ['平安社科大', 'https://welcome.ucass.edu.cn/info/1003/1261.htm'],
        ['中国社会科学院大学历史学院', 'https://history.ucass.edu.cn/info/1191/4711.htm'],
        ['社科大经济学院', 'https://se.ucass.edu.cn/info/1153/3309.htm'],
        ['社科大商学院', 'https://bs.ucass.edu.cn/info/1213/4206.htm'],
        ['社科大法学院', 'https://law.ucass.edu.cn/info/1053/4263.htm'],
        ['社科法硕', 'https://law.ucass.edu.cn/system/_content/download.jsp?owner=1689186152&urltype=news.DownloadAttachUrl&wbfileid=13263429'],
        ['UCASS LAW', 'https://law.ucass.edu.cn/info/1031/9021.htm'],
        ['社科大互联网法学', 'https://law.ucass.edu.cn/info/1053/4535.htm'],
        ['社科大科研处', 'https://law.ucass.edu.cn/info/1053/4535.htm'],
        ['中国社会科学院大学政府管理学院', 'https://sg.ucass.edu.cn/info/1149/4951.htm'],
        ['政管MPA', 'https://sg.ucass.edu.cn/info/1149/4951.htm'],
        ['社科大新闻传播学院', 'https://media.ucass.edu.cn/info/1019/2133.htm'],
        ['中国社会科学院大学应用经济学院', 'https://fae.ucass.edu.cn/info/1241/3674.htm'],
        ['中国社科大国际政治经济学院', 'https://xmjh.ucass.edu.cn/info/1036/1401.htm'],
        ['思创社科', 'https://marxism.ucass.edu.cn/info/1031/1093.htm'],
        ['中国社科大图书馆', 'https://www.ucass.edu.cn/zhongguoshehuikexueyuandaxue2020-2021xuenianxinxigongkaoniandugongzuobaogao.pdf'],
        ['社科大就业', 'https://yjsjy.ucass.edu.cn/__local/1/B1/82/1D8D60E752EEB43AE7CAAC8A14A_A981583F_641FAC.pdf'],
    ];
}

