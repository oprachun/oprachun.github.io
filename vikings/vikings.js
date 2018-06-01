'use strict';
function HTMLTable(ATableHE, AMRP, APNAlignToRight)
{
  function Init()
  {
    var
      LInitValue = JSON.parse(localStorage[LLSIdent] || '{}'),
      LNF = window.Intl ? new Intl.NumberFormat('en-US') : null;
    LTRs  = Array.prototype.slice.call(ATableHE.getElementsByTagName('tr'), 0);
    LTRTH = null;
    LParentHE = null;
    for(var i = LTRs.length - 1; i >= 0; i--)
      if((LTRs[i].parentNode.tagName === 'THEAD') || (LTRs[i].parentNode.tagName === 'TFOOT'))
        LTRs.shift();
      else
      if(LTRs[i].children[0].tagName === 'TH')
      {
        LTRTH = LTRs.shift();
        LParentHE = LTRTH.parentNode;
      }

    for(var i = 0, L = LTHs.length, LSortIndex = -1; i < L; i++)
    {
      LTHs[i].addEventListener('click', SortCol);
      LTHs[i].fssIndex = i;
      if(LTHs[i].textContent === LInitValue.ColName)
      {
        LTHs[i].fssDirection = LInitValue.Direction;
        LSortIndex = i;
      }
      else
        LTHs[i].fssDirection = 1;
      LTHs[i].style.cursor = 'pointer';
      LTHs[i].fssIsNumber = true;
      var LtextContent;
      for(var j = 0, LValue; j < LTRs.length; j++)
      {
        LtextContent = LTRs[j].children[i].textContent;
        if(parseFloat(LtextContent) != LtextContent)
        {
          LTHs[i].fssIsNumber = false;
          break;
        }
      }
      if(LTHs[i].fssIsNumber)
        for(var j = 0, LValue; j < LTRs.length; j++)
        {
          LTRs[j].children[i].className = 'Number';
          if(LNF)
            LTRs[j].children[i].innerHTML = LNF.format(LTRs[j].children[i].innerHTML);
        }
   }
   if(LSortIndex !== -1)
     SortCol({target: LTHs[LSortIndex]});
  }

  function PageNavigatorBuild()
  {
    function HECreate(ACaption, APageNo)
    {
      var LHE = LPageNavigatorHE.appendChild(document.createElement('span'));
      LHE.innerHTML = ACaption;
      LHE.addEventListener('click', function(){ PageNoSet(APageNo); });
      LHE.style.cursor = 'pointer';
      LHE.style.padding = '.1em .4em';
      return LHE;
    }

    function HEsUpdate(APageNo)
    {
      LHEs[LPageNo - 1].style.color = '';
      LHEs[APageNo - 1].style.color = 'red';
      LPrevHE.style.color = APageNo === 1 ? 'grey' : '';
      LNextHE.style.color = APageNo === LCount ? 'grey' : '';
    }

    function PageNoSet(APageNo)
    {
      if(APageNo < 1)
        APageNo = LPageNo - 1;
      else
      if(APageNo > LCount)
        APageNo = LPageNo + 1;
      else
      if(!APageNo)
        APageNo = LPageNo;

      if((APageNo >= 1) && (APageNo <= LCount))
      {
        HEsUpdate(APageNo);
        LPageNo = APageNo;
        var LStartIndex = (APageNo - 1) * AMRP;
        for(var i = 0, L = LTRs.length; i < L; i++)
          LTRs[i].style.display = ((i >= LStartIndex) && (i < LStartIndex + AMRP) ? '' : 'none');
      }
    }

    if((typeof AMRP !== 'number') || (AMRP < 0))
      AMRP = 0;
    if((AMRP <= 0) || (LTRs.length <= AMRP))
      return;
    var
      LCount = Math.ceil(LTRs.length / AMRP),
      LHEs = [],
      LPageNo = 1,
      LPageNavigatorHE = ATableHE.parentNode.insertBefore(document.createElement('div'), ATableHE),
      LPrevHE = HECreate('<', 0);
    if(APNAlignToRight)
      LPageNavigatorHE.style.textAlign = 'right';
    if('ontouchstart' in window)
      LPageNavigatorHE.style.fontSize = '32px';
    for(var i = 1; i <= LCount; i++)
      LHEs.push(HECreate(i, i));
    var LNextHE = HECreate('>', LCount + 1);
    PageNoSet(1);
    return PageNoSet;
  }

  function SortCol(AEvent)
  {
    function Sort(ATR1, ATR2)
    {
      function LNum(AValue)
      {
        return +AValue.replace(/,/g, '');
      }
      var
        LValue1 = ATR1.children[AEvent.currentTarget.fssIndex].textContent,
        LValue2 = ATR2.children[AEvent.currentTarget.fssIndex].textContent;
      if(AEvent.currentTarget.fssIsNumber)
        return (LNum(LValue1) - LNum(LValue2)) * AEvent.currentTarget.fssDirection;
      else
        return LValue1.toLowerCase().localeCompare(LValue2.toLowerCase()) * AEvent.currentTarget.fssDirection;
    }

    LTRs.sort(Sort);
    for(var i = 0, L = LTRs.length, LHE = LTRTH, LTR; i < L; i++)
    {
      LTR = LTRs[i];
      LParentHE.insertBefore(LTR, LHE.nextSibling);
      LHE = LTR;
    }
    for(var i = 0, L = LTHs.length; i < L; i++)
      if(AEvent.currentTarget === LTHs[i])
        LTHs[i].className = (LTHs[i].fssDirection === -1 ? 'ascend' : 'descend');
      else
        LTHs[i].className = '';
     if(LLSIdent)
      localStorage[LLSIdent] = JSON.stringify({
        ColName: LTRTH.children[AEvent.currentTarget.fssIndex].textContent,
        Direction: AEvent.currentTarget.fssDirection
      });
    AEvent.currentTarget.fssDirection *= -1;
    LPageNoSet && LPageNoSet();
  }

  function TableHEInit()
  {
    if(ATableHE.constructor === String)
      ATableHE = document.getElementById(ATableHE);
    if(ATableHE.constructor !== HTMLTableElement)
      throw new Error('Not html table');
  }

  TableHEInit();
  /*if('ontouchstart' in window)
    ATableHE.style.fontSize = '20px';*/
  var LTHs = ATableHE.getElementsByTagName('th'), LTRs, LTRTH, LParentHE;
  if(!LTHs.length)
    return;
  var LLSIdent = ATableHE.id ? location.pathname + ATableHE.id : '';
  Init();
  var LPageNoSet = PageNavigatorBuild();
}

(function()
{
  function StyleAdd(AName, AStyles)
  {
    if(document.styleSheets[0].addRule)
      document.styleSheets[0].addRule(AName, AStyles);
    else
      document.styleSheets[0].insertRule(AName + '{' + AStyles  + '}', 0);
  }
  if(!document.styleSheets[0])
    document.head.appendChild(document.createElement('style'));
  StyleAdd('th.ascend:after',  'content: "\\2193";');
  StyleAdd('th.descend:after', 'content: "\\2191";');
  StyleAdd('.Number', 'text-align: right;');
  /*if('ontouchstart' in window)
    StyleAdd('th', 'font-size: 32px;');*/
})();

function HEsBuild()
{
  var LThing, LName, LParams = {}, i, LPercents = [];
  for(i = 0; i < Things.length; i++)
  {
    LThing = Things[i];
    for(LName in LThing.percent)
    {
      LParams[LName] = true;
      if(LPercents.indexOf(LThing.percent[LName].join()) === -1)
        LPercents.push(LThing.percent[LName].join()); 
    }
  }                      
  LParams = Object.keys(LParams).sort();
  console.log(LParams.join('\r\n'));
  LPercents.sort();  
  //console.log(LPercents.join('\r\n'));  

  var LFragHE = document.createDocumentFragment();
  var LTableHE = LFragHE.appendChild(document.createElement('table'));
  var LTRTHHE = LTableHE.appendChild(document.createElement('tr'));
  var LTHHE; 
  LTRTHHE.appendChild(document.createElement('th')).innerHTML = '<div><span>Название</span></div>'; 
  LTRTHHE.appendChild(document.createElement('th')).innerHTML = '<div><span>Уровень'; 
  LTRTHHE.appendChild(document.createElement('th')).innerHTML = '<div><span>Категория'; 
  LTRTHHE.appendChild(document.createElement('th')).innerHTML = '<div><span>Тип';
  for(i = 0; i < LParams.length; i++)
    LTRTHHE.appendChild(document.createElement('th')).innerHTML = '<div><span>' + LParams[i] + '</span></div>'; 

  for(i = 0; i < Things.length; i++)
  {
    LThing = Things[i];
    var LTRHE = LTableHE.appendChild(document.createElement('tr'));
    LTRHE.appendChild(document.createElement('td')).innerHTML = LThing.name;
    LTRHE.appendChild(document.createElement('td')).innerHTML = LThing.level;
    LTRHE.appendChild(document.createElement('td')).innerHTML = LThing.category;
    LTRHE.appendChild(document.createElement('td')).innerHTML = LThing.type;
    for(var j = 0; j < LParams.length; j++)
    {
      var LTDHE = LTRHE.appendChild(document.createElement('td'));
      var LPercent = LThing.percent[LParams[j]];
      LTDHE.innerHTML = (LPercent ? LPercent[5] : '');  
    }
  }                      
  document.body.appendChild(LFragHE);
  HTMLTable(LTableHE);
}
HEsBuild();