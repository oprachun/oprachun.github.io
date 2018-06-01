'use strict';

function currenciesLoad()
{
  var lRequest = new XMLHttpRequest();
  lRequest.open('GET', 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?json');
  lRequest.onreadystatechange = currenciesLoaded;
  lRequest.send();
}

function currenciesLoaded(aEvent)
{
  if(aEvent.target.readyState !== XMLHttpRequest.DONE)
    return;
  if(aEvent.target.status !== 200)
  {
    alert(aEvent.target.status + ' ' + aEvent.target.response);
    return;
  }
  window.currencies = JSON.parse(aEvent.target.response);
  currencies.push({cc: 'UAH', txt: 'Українська гривня', rate: 1});
  inputsInit();
}

function inputsInit()
{
  window.valueHE = document.getElementById('value');
  window.resultHE = document.getElementById('result');
  window.currencyFromHE = document.getElementById('currency_from');
  window.currencyToHE = document.getElementById('currency_to');
  currenciesInit(currencyFromHE);
  currenciesInit(currencyToHE);
  var lCalcHE = document.getElementById('calc');
  lCalcHE.disabled = false;
  lCalcHE.addEventListener('click', calculate);
}

function currenciesInit(aParentHE)
{
  var lFragHE = document.createDocumentFragment();
  var lCurrency;
  var lOptionHE;
  lOptionHE = lFragHE.appendChild(document.createElement('option'));
  lOptionHE.textContent = 'Select currency';
  lOptionHE.value = -1;  
  for(var i = 0; i < currencies.length; i++)
  {
    lCurrency = currencies[i]; 
    lOptionHE = lFragHE.appendChild(document.createElement('option'));
    lOptionHE.value = i;
    lOptionHE.textContent = lCurrency.cc + ' ' + lCurrency.txt;  
  }
  aParentHE.appendChild(lFragHE);
}

function calculate()
{
  function lValidate()
  {
    var lErrorMessages = [];
    if((typeof lValue !== 'number') || (isNaN(lValue)))
      lErrorMessages.push('Set valid value');
    if(lCurrencyFromIndex === '-1')
      lErrorMessages.push('Select currency from');
    if(lCurrencyToIndex === '-1')
      lErrorMessages.push('Select currency to');
    if(lErrorMessages.length)
      alert(lErrorMessages.join('\n'));
    return lErrorMessages.length === 0;
  }
  var lValue = +valueHE.value;
  var lCurrencyFromIndex = currencyFromHE.value;
  var lCurrencyToIndex = currencyToHE.value;
  if(!lValidate())
    return;
  var lCurrencyFrom = currencies[lCurrencyFromIndex]; 
  var lCurrencyTo = currencies[lCurrencyToIndex];
  var lResult = round(lValue * lCurrencyFrom.rate / lCurrencyTo.rate, 2);
  resultHE.textContent = lValue + ' ' + lCurrencyFrom.cc + ' = ' + lResult + ' ' + lCurrencyTo.cc;   
}

function round(aNumber, aPrecision) 
{
  var lFactor = Math.pow(10, aPrecision);
  return Math.round(aNumber * lFactor) / lFactor;
}

document.addEventListener('DOMContentLoaded', currenciesLoad);