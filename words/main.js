'use strict';

var Indexes;
document.addEventListener('DOMContentLoaded', OnLoad);

function OnLoad()
{
  Indexes = new Array(Words.length);
  for(var i = 0; i < Words.length; i++)
    Indexes[i] = PrepareIndex(Words[i]);
  LoadInputs();
  Prepare();
  var LWordsHE = document.getElementById('words');
  var LHE;
  for(var i = 0; i < Words2.length; i++)
  {
    LHE = LWordsHE.appendChild(document.createElement('span'));
    LHE.textContent = Words2[i];
    LHE.value = Words2[i];
    LHE.className = 'word';

    LHE = LHE.appendChild(document.createElement('span'));
    LHE.textContent = CalcCount(Words2[i]);
    LHE.className = 'word-count';
  }
}

function IndexSorted(AArray, AValue)
{
  var LLow = 0;
  var LHigh = AArray.length;
  var LMid;
  while(LLow < LHigh)
  {
    LMid = LLow + LHigh >>> 1;
    if(AArray[LMid] < AValue)
      LLow = LMid + 1;
    else
      LHigh = LMid;
	}
  return LLow;
}

function SortedExists(AArray, AValue)
{
  var LIndex = IndexSorted(AArray, AValue);
  return AValue === AArray[LIndex];
  //return AArray.indexOf(AValue) !== -1;
}

function InsertSorted(AArray, AValue)
{
  var LIndex = IndexSorted(AArray, AValue);
  if(AArray[LIndex] !== AValue)
	  AArray.splice(LIndex, 0, AValue);
}

function Prepare(AEvent)
{
  if(AEvent)
    AEvent.preventDefault();
  var LWord = document.getElementById('word').value.toLowerCase();
  if(!LWord)
    return;
  /*var LCount = +document.getElementById('count').value;
  var LOnlyFromVocabulary = document.getElementById('voc').checked;*/
  var LList = Calc2(LWord);
  /*for(var i = 1; i <= LWord.length; i++)
    Calc(LList, LWord, i, true);
  Calc(LList, LWord, LCount, LOnlyFromVocabulary);*/
  View(LList);
}

function Calc(AList, AWord, ACount, AOnlyFromVocabulary)
{
  function LAdd(AValues)
  {
    var LElement = '';
    for(var i = 0; i < AValues.length; i++)
      LElement += AWord[AValues[i]];
    if(!AOnlyFromVocabulary || SortedExists(Words, LElement))
      InsertSorted(AList[ACount], LElement);
  }
  function LNext(AValues, AIndexes)
  {
    for(var i = AValues.length - 1; i >= 0; i--)
    {
      AIndexes[AValues[i]] = false;
      if(AValues[i] < AWord.length - 1)
      {
        for(var j = AValues[i] + 1; j < AIndexes.length; j++)
          if(!AIndexes[j])
            break;
        if(j >= AIndexes.length)
          AValues[i] = -1;
        else
        {
          AValues[i] = j;
          AIndexes[j] = true;
          break;
        }
      }
      else
        AValues[i] = -1;
    }
    if(i === -1)
      return false;
    for(i = i + 1; i < AValues.length; i++)
    {
      for(var j = 0; j < AIndexes.length; j++)
        if(!AIndexes[j])
          break;
      AValues[i] = j;
      AIndexes[j] = true;
    }
    return true;
  }
  AList[ACount] = [];
  var LValues = [];
  var LIndexes = [];
  for(var i = 0; i < AWord.length; i++)
    LIndexes[i] = false;

  for(var i = 0; i < ACount; i++)
  {
    LValues.push(i);
    LIndexes[i] = true;
  }
  LAdd(LValues);
  while(LNext(LValues, LIndexes))
    LAdd(LValues)
  AList[ACount] = AList[ACount].sort(function(a,b){ return a.localeCompare(b, 'uk'); });
}

function Calc2(AWord)
{
  function LExists(AIndex, AMainIndex)
  {
    for(var i = 0; i < AIndex.length; i++)
      if(AIndex[i] > AMainIndex[i])
        return false;
    return true;
  }
  var LWordIndex = PrepareIndex(AWord);
  var LCount = 0;
  var LList = [];
  for(var i = 0; i < Indexes.length; i++)
  {
    if(LExists(Indexes[i], LWordIndex))
    {
      LCount = Words[i].length;
      if(!LList[LCount])
        LList[LCount] = [];
      LList[LCount].push(Words[i]);
    }
  }
  return LList;
}

function CalcCount(AWord)
{
  function LExists(AIndex, AMainIndex)
  {
    for(var i = 0; i < AIndex.length; i++)
      if(AIndex[i] > AMainIndex[i])
        return false;
    return true;
  }
  var LWordIndex = PrepareIndex(AWord);
  var LCount = 0;
  for(var i = 0; i < Indexes.length; i++)
    if(LExists(Indexes[i], LWordIndex))
      LCount++;
  return LCount;
}

function View(AList)
{
  var LFragHE = document.createDocumentFragment();
  var i, j, LTRHE, LTDHE, LMax = 0;
  LTRHE = LFragHE.appendChild(document.createElement('thead')).appendChild(document.createElement('tr'));
  for(i = 1; i < AList.length; i++)
  {
    if(!AList[i])
      continue;
    LTRHE.appendChild(document.createElement('th')).innerHTML = i + '(' + AList[i].length + ')';
    if(LMax < AList[i].length)
      LMax = AList[i].length;
  }
  for(i = 0; i < LMax; i++)
  {
    LTRHE = LFragHE.appendChild(document.createElement('tr'));
    for(j = 1; j < AList.length; j++)
    {
      if(!AList[j])
        continue;
      LTDHE = LTRHE.appendChild(document.createElement('td'));
      if(AList[j][i]) {
        LTDHE.innerHTML = AList[j][i];
        if(WordsPrev.indexOf(AList[j][i]) === -1)
          LTDHE.className = 'is-new';
      }
    }
  }
  var LTableHE = document.getElementById('values');
  LTableHE.innerHTML = '';
  LTableHE.appendChild(LFragHE);
}

function Filter()
{
  function LVisibleCalc(AText)
  {
    if(LFrom && LTo)
      return (AText > LFrom) && (AText < LTo);
    else
    if(LFrom)
      return (AText > LFrom);
    else
    if(LTo)
      return (AText < LTo);
    else
      return true;
  }
  var LFrom = document.getElementById('filter_from').value.toLowerCase();
  var LTo = document.getElementById('filter_to').value.toLowerCase();
  var LTableHE = document.getElementById('values');
  var LTDHEs = LTableHE.getElementsByTagName('td');
  for(var i = 0; i < LTDHEs.length; i++)
    LTDHEs[i].style.display = LVisibleCalc(LTDHEs[i].textContent) ? '' : 'none';
}

function LoadInputs()
{
  var LKeys = Object.keys(localStorage);
  var LIdent = 'input_';
  var LHE, LID;
  for(var i = 0; i < LKeys.length; i++)
    if(LKeys[i].substr(0, LIdent.length) === LIdent)
    {
      LID = LKeys[i].substr(LIdent.length);
      LHE = document.getElementById(LID);
      if(LHE)
        if(LHE.type === 'checkbox')
          LHE.checked = JSON.parse(localStorage[LKeys[i]]);
        else
          LHE.value = localStorage[LKeys[i]].toLowerCase();
    }
}

function SaveInput(AEvent)
{
  localStorage.setItem('input_' + AEvent.target.id,
    AEvent.target.type === 'checkbox' ? AEvent.target.checked : AEvent.target.value.toLowerCase());
}

function PrepareIndex(AWord)
{
  var LAlphabet = 'абвгдеєжзиіїйклмнопрстуфхцчшщьюя';
  var LResult = new Array(LAlphabet.length);
  var LIndex = 0;
  for(var i = 0; i < LAlphabet.length; i++)
    LResult[i] = 0;
  for(var i = 0; i < AWord.length; i++)
  {
    LIndex = LAlphabet.indexOf(AWord[i]);
    if(LIndex === -1)
      throw new Error('Not found "' + AWord[i] + '" in "' + AWord + '"');
    LResult[LIndex]++;
  }
  return LResult;
}

function WordSelect(AEvent)
{
  document.getElementById('word').value = AEvent.target.value;
  var LWordsHE = document.getElementById('words');
  for(var i = 0; i < LWordsHE.children.length; i++)
    if(LWordsHE.children[i].style.backgroundColor)
      LWordsHE.children[i].style.backgroundColor = '';
  AEvent.target.style.backgroundColor = 'beige';
  SaveInput({target: document.getElementById('word')});
  Prepare();
}
