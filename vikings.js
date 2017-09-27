'use strict';
var things = {
  'Шлемы': {
    'Стандартные вещи': [
      {
        name: 'Древний Шлем',
        level: 50,
        percent: {
          'урон убийц': [9, 13.5, 18, 22.5, 27, 36],
          'здоровье всех войск': [3, 4.5, 6, 7.5, 9, 12]
        }
      },
      {
        name: 'Заговоренный Шлем',
        level: 50,
        percent: {
          'здоровье всех войск': [9, 13.5, 18, 22.5, 27, 36],
          'урон пехоты': [3, 4.5, 6, 7.5, 9, 12]
        }
      },
      {
        name: 'Серебряный Шлем',
        level: 50,
        percent: {
          'урон стрелков': [9, 13.5, 18, 22.5, 27, 36],
          'защита всех войск': [3, 4.5, 6, 7.5, 9, 12]
        }
      },
      {
        name: 'Шлем тирана',
        level: 50,
        percent: {
          'урон кавалерии': [9, 13.5, 18, 22.5, 27, 36],
          'производство еды': [24, 48, 72, 96, 120, 180]
        }
      },
      {
        name: 'Шлем ярла',
        level: 50,
        percent: {
          'производство еды': [48, 96, 144, 192, 240, 360],
          'урон пехоты': [6, 9, 12, 15, 18, 24]
        }
      },
      {
        name: 'Шлем Разрушителя',
        level: 45,
        percent: {
          'производство серебра': [11, 22, 33, 44, 55, 82.5],
          'урон пехоты': [2.8, 4.1, 5.5, 6.9, 8.3, 11],
          'урон убийц': [5.6, 8.2, 11, 13.8, 16.6, 22]
        }
      },
      {
        name: 'Шлем Убийцы',
        level: 45,
        percent: {
          'производство еды': [22, 44, 66, 88, 110, 165],
          'урон пехоты': [2.8, 4.1, 5.5, 6.9, 8.3, 11],
          'урон осадных войск': [5.6, 8.2, 11, 13.8, 16.6, 22]
        }
      },
      {
        name: 'Маска Локи',
        level: 40,
        percent: {
          'грузоподъемность отряда': [5, 10, 15, 20, 25, 30],
          'урон стрелков': [7.5, 11.4, 15, 18.9, 22.5, 30]
        }
      },
      {
        name: 'Шлем Воителя',
        level: 35,
        percent: {
          'защита всех войск': [4.6,6.8,9,11.2,13.6,18],
          'здоровье всех войск': [2.3,3.4,4.5,5.6,6.8,9],
          'урон убийц': [2.3,3.4,4.5,5.6,6.8,9]
        }
      }/*,
      {
        name: '',
        level: ,
        percent: {
          '': [],
          '': [],
          '': [],
          '': []
        }
      },
      {
        name: '',
        level: ,
        percent: {
          '': [],
          '': [],
          '': [],
          '': []
        }
      },*/
    ],
    'Вещи захватчиков': [
    ],
    'Специальные вещи': [
    ]
  },
  'Броня': {
    'Стандартные вещи': [
    ],
    'Вещи захватчиков': [
    ],
    'Специальные вещи': [
    ]
  },
  'Оружие': {
    'Стандартные вещи': [
    ],
    'Вещи захватчиков': [
    ],
    'Специальные вещи': [
    ]
  },
  'Обувь': {
    'Стандартные вещи': [
    ],
    'Вещи захватчиков': [
    ],
    'Специальные вещи': [
    ]
  },
  'Амулеты': {
    'Стандартные вещи': [
    ],
    'Вещи захватчиков': [
    ],
    'Специальные вещи': [
    ]
  }
};

function HEsBuild()
{
  var LThing, LName, LParams = {}, i;
  for(i = 0; i < Things.length; i++)
  {
    LThing = Things[i];
    for(LName in LThing.percent)
      LParams[LName] = true;
  }                      
  LParams = Object.keys(LParams).sort();  

  var LFragHE = document.createDocumentFragment();
  var LTableHE = LFragHE.appendChild(document.createElement('table'));
  var LTRTHHE = LTableHE.appendChild(document.createElement('thead')).appendChild(document.createElement('tr'));
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
}
HEsBuild();
