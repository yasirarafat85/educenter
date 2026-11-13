/**
 * @license lucide (subset: 147 icons — build/lucide-subset.js দিয়ে তৈরি, হাতে এডিট করবেন না) v0.469.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */

(function (global, factory) {
  typeof exports === 'object' && typeof module !== 'undefined' ? factory(exports) :
  typeof define === 'function' && define.amd ? define(['exports'], factory) :
  (global = typeof globalThis !== 'undefined' ? globalThis : global || self, factory(global.lucide = {}));
})(this, (function (exports) { 'use strict';

  const createElement = (tag, attrs, children = []) => {
    const element = document.createElementNS("http://www.w3.org/2000/svg", tag);
    Object.keys(attrs).forEach((name) => {
      element.setAttribute(name, String(attrs[name]));
    });
    if (children.length) {
      children.forEach((child) => {
        const childElement = createElement(...child);
        element.appendChild(childElement);
      });
    }
    return element;
  };
  var createElement$1 = ([tag, attrs, children]) => createElement(tag, attrs, children);

  const getAttrs = (element) => Array.from(element.attributes).reduce((attrs, attr) => {
    attrs[attr.name] = attr.value;
    return attrs;
  }, {});
  const getClassNames = (attrs) => {
    if (typeof attrs === "string")
      return attrs;
    if (!attrs || !attrs.class)
      return "";
    if (attrs.class && typeof attrs.class === "string") {
      return attrs.class.split(" ");
    }
    if (attrs.class && Array.isArray(attrs.class)) {
      return attrs.class;
    }
    return "";
  };
  const combineClassNames = (arrayOfClassnames) => {
    const classNameArray = arrayOfClassnames.flatMap(getClassNames);
    return classNameArray.map((classItem) => classItem.trim()).filter(Boolean).filter((value, index, self) => self.indexOf(value) === index).join(" ");
  };
  const toPascalCase = (string) => string.replace(/(\w)(\w*)(_|-|\s*)/g, (g0, g1, g2) => g1.toUpperCase() + g2.toLowerCase());
  const replaceElement = (element, { nameAttr, icons, attrs }) => {
    const iconName = element.getAttribute(nameAttr);
    if (iconName == null)
      return;
    const ComponentName = toPascalCase(iconName);
    const iconNode = icons[ComponentName];
    if (!iconNode) {
      return console.warn(
        `${element.outerHTML} icon name was not found in the provided icons object.`
      );
    }
    const elementAttrs = getAttrs(element);
    const [tag, iconAttributes, children] = iconNode;
    const iconAttrs = {
      ...iconAttributes,
      "data-lucide": iconName,
      ...attrs,
      ...elementAttrs
    };
    const classNames = combineClassNames(["lucide", `lucide-${iconName}`, elementAttrs, attrs]);
    if (classNames) {
      Object.assign(iconAttrs, {
        class: classNames
      });
    }
    const svgElement = createElement$1([tag, iconAttrs, children]);
    return element.parentNode?.replaceChild(svgElement, element);
  };

  const defaultAttributes = {
    xmlns: "http://www.w3.org/2000/svg",
    width: 24,
    height: 24,
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    "stroke-width": 2,
    "stroke-linecap": "round",
    "stroke-linejoin": "round"
  };

  const Archive = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "20", height: "5", x: "2", y: "3", rx: "1" }],
      ["path", { d: "M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8" }],
      ["path", { d: "M10 12h4" }]
    ]
  ];

  const ArrowLeft = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "m12 19-7-7 7-7" }],
      ["path", { d: "M19 12H5" }]
    ]
  ];

  const ArrowRight = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M5 12h14" }],
      ["path", { d: "m12 5 7 7-7 7" }]
    ]
  ];

  const ArrowUp = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "m5 12 7-7 7 7" }],
      ["path", { d: "M12 19V5" }]
    ]
  ];

  const Award = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"
        }
      ],
      ["circle", { cx: "12", cy: "8", r: "6" }]
    ]
  ];

  const Baby = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M9 12h.01" }],
      ["path", { d: "M15 12h.01" }],
      ["path", { d: "M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5" }],
      [
        "path",
        {
          d: "M19 6.3a9 9 0 0 1 1.8 3.9 2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3c2 0 3.5 1.1 3.5 2.5s-.9 2.5-2 2.5c-.8 0-1.5-.4-1.5-1"
        }
      ]
    ]
  ];

  const BadgeCheck = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"
        }
      ],
      ["path", { d: "m9 12 2 2 4-4" }]
    ]
  ];

  const Bell = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M10.268 21a2 2 0 0 0 3.464 0" }],
      [
        "path",
        {
          d: "M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"
        }
      ]
    ]
  ];

  const BookOpen = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M12 7v14" }],
      [
        "path",
        {
          d: "M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"
        }
      ]
    ]
  ];

  const Cake = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8" }],
      ["path", { d: "M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1" }],
      ["path", { d: "M2 21h20" }],
      ["path", { d: "M7 8v3" }],
      ["path", { d: "M12 8v3" }],
      ["path", { d: "M17 8v3" }],
      ["path", { d: "M7 4h.01" }],
      ["path", { d: "M12 4h.01" }],
      ["path", { d: "M17 4h.01" }]
    ]
  ];

  const Calendar = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M8 2v4" }],
      ["path", { d: "M16 2v4" }],
      ["rect", { width: "18", height: "18", x: "3", y: "4", rx: "2" }],
      ["path", { d: "M3 10h18" }]
    ]
  ];

  const CalendarCheck = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M8 2v4" }],
      ["path", { d: "M16 2v4" }],
      ["rect", { width: "18", height: "18", x: "3", y: "4", rx: "2" }],
      ["path", { d: "M3 10h18" }],
      ["path", { d: "m9 16 2 2 4-4" }]
    ]
  ];

  const CalendarClock = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M21 7.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3.5" }],
      ["path", { d: "M16 2v4" }],
      ["path", { d: "M8 2v4" }],
      ["path", { d: "M3 10h5" }],
      ["path", { d: "M17.5 17.5 16 16.3V14" }],
      ["circle", { cx: "16", cy: "16", r: "6" }]
    ]
  ];

  const CalendarDays = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M8 2v4" }],
      ["path", { d: "M16 2v4" }],
      ["rect", { width: "18", height: "18", x: "3", y: "4", rx: "2" }],
      ["path", { d: "M3 10h18" }],
      ["path", { d: "M8 14h.01" }],
      ["path", { d: "M12 14h.01" }],
      ["path", { d: "M16 14h.01" }],
      ["path", { d: "M8 18h.01" }],
      ["path", { d: "M12 18h.01" }],
      ["path", { d: "M16 18h.01" }]
    ]
  ];

  const Cat = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M12 5c.67 0 1.35.09 2 .26 1.78-2 5.03-2.84 6.42-2.26 1.4.58-.42 7-.42 7 .57 1.07 1 2.24 1 3.44C21 17.9 16.97 21 12 21s-9-3-9-7.56c0-1.25.5-2.4 1-3.44 0 0-1.89-6.42-.5-7 1.39-.58 4.72.23 6.5 2.23A9.04 9.04 0 0 1 12 5Z"
        }
      ],
      ["path", { d: "M8 14v.5" }],
      ["path", { d: "M16 14v.5" }],
      ["path", { d: "M11.25 16.25h1.5L12 17l-.75-.75Z" }]
    ]
  ];

  const ChartColumn = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M3 3v16a2 2 0 0 0 2 2h16" }],
      ["path", { d: "M18 17V9" }],
      ["path", { d: "M13 17V5" }],
      ["path", { d: "M8 17v-3" }]
    ]
  ];

  const ChartPie = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M21 12c.552 0 1.005-.449.95-.998a10 10 0 0 0-8.953-8.951c-.55-.055-.998.398-.998.95v8a1 1 0 0 0 1 1z"
        }
      ],
      ["path", { d: "M21.21 15.89A10 10 0 1 1 8 2.83" }]
    ]
  ];

  const Check = ["svg", defaultAttributes, [["path", { d: "M20 6 9 17l-5-5" }]]];

  const ChevronDown = ["svg", defaultAttributes, [["path", { d: "m6 9 6 6 6-6" }]]];

  const ChevronRight = ["svg", defaultAttributes, [["path", { d: "m9 18 6-6-6-6" }]]];

  const CircleAlert = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "12", cy: "12", r: "10" }],
      ["line", { x1: "12", x2: "12", y1: "8", y2: "12" }],
      ["line", { x1: "12", x2: "12.01", y1: "16", y2: "16" }]
    ]
  ];

  const CircleCheck = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "12", cy: "12", r: "10" }],
      ["path", { d: "m9 12 2 2 4-4" }]
    ]
  ];

  const CircleHelp = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "12", cy: "12", r: "10" }],
      ["path", { d: "M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" }],
      ["path", { d: "M12 17h.01" }]
    ]
  ];

  const CirclePlay = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "12", cy: "12", r: "10" }],
      ["polygon", { points: "10 8 16 12 10 16 10 8" }]
    ]
  ];

  const CirclePlus = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "12", cy: "12", r: "10" }],
      ["path", { d: "M8 12h8" }],
      ["path", { d: "M12 8v8" }]
    ]
  ];

  const CircleUser = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "12", cy: "12", r: "10" }],
      ["circle", { cx: "12", cy: "10", r: "3" }],
      ["path", { d: "M7 20.662V19a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1.662" }]
    ]
  ];

  const CircleX = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "12", cy: "12", r: "10" }],
      ["path", { d: "m15 9-6 6" }],
      ["path", { d: "m9 9 6 6" }]
    ]
  ];

  const Clapperboard = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M20.2 6 3 11l-.9-2.4c-.3-1.1.3-2.2 1.3-2.5l13.5-4c1.1-.3 2.2.3 2.5 1.3Z" }],
      ["path", { d: "m6.2 5.3 3.1 3.9" }],
      ["path", { d: "m12.4 3.4 3.1 4" }],
      ["path", { d: "M3 11h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" }]
    ]
  ];

  const ClipboardCheck = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "8", height: "4", x: "8", y: "2", rx: "1", ry: "1" }],
      ["path", { d: "M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" }],
      ["path", { d: "m9 14 2 2 4-4" }]
    ]
  ];

  const ClipboardList = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "8", height: "4", x: "8", y: "2", rx: "1", ry: "1" }],
      ["path", { d: "M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" }],
      ["path", { d: "M12 11h4" }],
      ["path", { d: "M12 16h4" }],
      ["path", { d: "M8 11h.01" }],
      ["path", { d: "M8 16h.01" }]
    ]
  ];

  const Clock = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "12", cy: "12", r: "10" }],
      ["polyline", { points: "12 6 12 12 16 14" }]
    ]
  ];

  const Code = [
    "svg",
    defaultAttributes,
    [
      ["polyline", { points: "16 18 22 12 16 6" }],
      ["polyline", { points: "8 6 2 12 8 18" }]
    ]
  ];

  const Columns2 = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "18", height: "18", x: "3", y: "3", rx: "2" }],
      ["path", { d: "M12 3v18" }]
    ]
  ];

  const Contact = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M16 2v2" }],
      ["path", { d: "M7 22v-2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2" }],
      ["path", { d: "M8 2v2" }],
      ["circle", { cx: "12", cy: "11", r: "3" }],
      ["rect", { x: "3", y: "4", width: "18", height: "18", rx: "2" }]
    ]
  ];

  const Copy = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "14", height: "14", x: "8", y: "8", rx: "2", ry: "2" }],
      ["path", { d: "M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" }]
    ]
  ];

  const CreditCard = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "20", height: "14", x: "2", y: "5", rx: "2" }],
      ["line", { x1: "2", x2: "22", y1: "10", y2: "10" }]
    ]
  ];

  const Database = [
    "svg",
    defaultAttributes,
    [
      ["ellipse", { cx: "12", cy: "5", rx: "9", ry: "3" }],
      ["path", { d: "M3 5V19A9 3 0 0 0 21 19V5" }],
      ["path", { d: "M3 12A9 3 0 0 0 21 12" }]
    ]
  ];

  const Delete = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M10 5a2 2 0 0 0-1.344.519l-6.328 5.74a1 1 0 0 0 0 1.481l6.328 5.741A2 2 0 0 0 10 19h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2z"
        }
      ],
      ["path", { d: "m12 9 6 6" }],
      ["path", { d: "m18 9-6 6" }]
    ]
  ];

  const Download = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" }],
      ["polyline", { points: "7 10 12 15 17 10" }],
      ["line", { x1: "12", x2: "12", y1: "15", y2: "3" }]
    ]
  ];

  const Eye = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"
        }
      ],
      ["circle", { cx: "12", cy: "12", r: "3" }]
    ]
  ];

  const EyeOff = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"
        }
      ],
      ["path", { d: "M14.084 14.158a3 3 0 0 1-4.242-4.242" }],
      [
        "path",
        {
          d: "M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"
        }
      ],
      ["path", { d: "m2 2 20 20" }]
    ]
  ];

  const Facebook = [
    "svg",
    defaultAttributes,
    [["path", { d: "M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z" }]]
  ];

  const File = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" }],
      ["path", { d: "M14 2v4a2 2 0 0 0 2 2h4" }]
    ]
  ];

  const FileText = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" }],
      ["path", { d: "M14 2v4a2 2 0 0 0 2 2h4" }],
      ["path", { d: "M10 9H8" }],
      ["path", { d: "M16 13H8" }],
      ["path", { d: "M16 17H8" }]
    ]
  ];

  const Files = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M20 7h-3a2 2 0 0 1-2-2V2" }],
      ["path", { d: "M9 18a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h7l4 4v10a2 2 0 0 1-2 2Z" }],
      ["path", { d: "M3 7.6v12.8A1.6 1.6 0 0 0 4.6 22h9.8" }]
    ]
  ];

  const Fingerprint = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4" }],
      ["path", { d: "M14 13.12c0 2.38 0 6.38-1 8.88" }],
      ["path", { d: "M17.29 21.02c.12-.6.43-2.3.5-3.02" }],
      ["path", { d: "M2 12a10 10 0 0 1 18-6" }],
      ["path", { d: "M2 16h.01" }],
      ["path", { d: "M21.8 16c.2-2 .131-5.354 0-6" }],
      ["path", { d: "M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2" }],
      ["path", { d: "M8.65 22c.21-.66.45-1.32.57-2" }],
      ["path", { d: "M9 6.8a6 6 0 0 1 9 5.2v2" }]
    ]
  ];

  const Flame = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"
        }
      ]
    ]
  ];

  const Focus = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "12", cy: "12", r: "3" }],
      ["path", { d: "M3 7V5a2 2 0 0 1 2-2h2" }],
      ["path", { d: "M17 3h2a2 2 0 0 1 2 2v2" }],
      ["path", { d: "M21 17v2a2 2 0 0 1-2 2h-2" }],
      ["path", { d: "M7 21H5a2 2 0 0 1-2-2v-2" }]
    ]
  ];

  const Folder = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"
        }
      ]
    ]
  ];

  const Footprints = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M4 16v-2.38C4 11.5 2.97 10.5 3 8c.03-2.72 1.49-6 4.5-6C9.37 2 10 3.8 10 5.5c0 3.11-2 5.66-2 8.68V16a2 2 0 1 1-4 0Z"
        }
      ],
      [
        "path",
        {
          d: "M20 20v-2.38c0-2.12 1.03-3.12 1-5.62-.03-2.72-1.49-6-4.5-6C14.63 6 14 7.8 14 9.5c0 3.11 2 5.66 2 8.68V20a2 2 0 1 0 4 0Z"
        }
      ],
      ["path", { d: "M16 17h4" }],
      ["path", { d: "M4 13h4" }]
    ]
  ];

  const GraduationCap = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"
        }
      ],
      ["path", { d: "M22 10v6" }],
      ["path", { d: "M6 12.5V16a6 3 0 0 0 12 0v-3.5" }]
    ]
  ];

  const Grid3x3 = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "18", height: "18", x: "3", y: "3", rx: "2" }],
      ["path", { d: "M3 9h18" }],
      ["path", { d: "M3 15h18" }],
      ["path", { d: "M9 3v18" }],
      ["path", { d: "M15 3v18" }]
    ]
  ];

  const Group = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M3 7V5c0-1.1.9-2 2-2h2" }],
      ["path", { d: "M17 3h2c1.1 0 2 .9 2 2v2" }],
      ["path", { d: "M21 17v2c0 1.1-.9 2-2 2h-2" }],
      ["path", { d: "M7 21H5c-1.1 0-2-.9-2-2v-2" }],
      ["rect", { width: "7", height: "5", x: "7", y: "7", rx: "1" }],
      ["rect", { width: "7", height: "5", x: "10", y: "12", rx: "1" }]
    ]
  ];

  const HardDriveDownload = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M12 2v8" }],
      ["path", { d: "m16 6-4 4-4-4" }],
      ["rect", { width: "20", height: "8", x: "2", y: "14", rx: "2" }],
      ["path", { d: "M6 18h.01" }],
      ["path", { d: "M10 18h.01" }]
    ]
  ];

  const Heading = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M6 12h12" }],
      ["path", { d: "M6 20V4" }],
      ["path", { d: "M18 20V4" }]
    ]
  ];

  const Headphones = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"
        }
      ]
    ]
  ];

  const HeartHandshake = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"
        }
      ],
      [
        "path",
        {
          d: "M12 5 9.04 7.96a2.17 2.17 0 0 0 0 3.08c.82.82 2.13.85 3 .07l2.07-1.9a2.82 2.82 0 0 1 3.79 0l2.96 2.66"
        }
      ],
      ["path", { d: "m18 15-2-2" }],
      ["path", { d: "m15 18-2-2" }]
    ]
  ];

  const History = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" }],
      ["path", { d: "M3 3v5h5" }],
      ["path", { d: "M12 7v5l4 2" }]
    ]
  ];

  const House = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" }],
      [
        "path",
        {
          d: "M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"
        }
      ]
    ]
  ];

  const Image = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "18", height: "18", x: "3", y: "3", rx: "2", ry: "2" }],
      ["circle", { cx: "9", cy: "9", r: "2" }],
      ["path", { d: "m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" }]
    ]
  ];

  const Import = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M12 3v12" }],
      ["path", { d: "m8 11 4 4 4-4" }],
      ["path", { d: "M8 5H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-4" }]
    ]
  ];

  const Inbox = [
    "svg",
    defaultAttributes,
    [
      ["polyline", { points: "22 12 16 12 14 15 10 15 8 12 2 12" }],
      [
        "path",
        {
          d: "M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"
        }
      ]
    ]
  ];

  const Info = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "12", cy: "12", r: "10" }],
      ["path", { d: "M12 16v-4" }],
      ["path", { d: "M12 8h.01" }]
    ]
  ];

  const Key = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4" }],
      ["path", { d: "m21 2-9.6 9.6" }],
      ["circle", { cx: "7.5", cy: "15.5", r: "5.5" }]
    ]
  ];

  const Layers = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"
        }
      ],
      ["path", { d: "M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12" }],
      ["path", { d: "M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17" }]
    ]
  ];

  const LayoutDashboard = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "7", height: "9", x: "3", y: "3", rx: "1" }],
      ["rect", { width: "7", height: "5", x: "14", y: "3", rx: "1" }],
      ["rect", { width: "7", height: "9", x: "14", y: "12", rx: "1" }],
      ["rect", { width: "7", height: "5", x: "3", y: "16", rx: "1" }]
    ]
  ];

  const LayoutGrid = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "7", height: "7", x: "3", y: "3", rx: "1" }],
      ["rect", { width: "7", height: "7", x: "14", y: "3", rx: "1" }],
      ["rect", { width: "7", height: "7", x: "14", y: "14", rx: "1" }],
      ["rect", { width: "7", height: "7", x: "3", y: "14", rx: "1" }]
    ]
  ];

  const Link = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" }],
      ["path", { d: "M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" }]
    ]
  ];

  const List = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M3 12h.01" }],
      ["path", { d: "M3 18h.01" }],
      ["path", { d: "M3 6h.01" }],
      ["path", { d: "M8 12h13" }],
      ["path", { d: "M8 18h13" }],
      ["path", { d: "M8 6h13" }]
    ]
  ];

  const ListChecks = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "m3 17 2 2 4-4" }],
      ["path", { d: "m3 7 2 2 4-4" }],
      ["path", { d: "M13 6h8" }],
      ["path", { d: "M13 12h8" }],
      ["path", { d: "M13 18h8" }]
    ]
  ];

  const Lock = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "18", height: "11", x: "3", y: "11", rx: "2", ry: "2" }],
      ["path", { d: "M7 11V7a5 5 0 0 1 10 0v4" }]
    ]
  ];

  const LogIn = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" }],
      ["polyline", { points: "10 17 15 12 10 7" }],
      ["line", { x1: "15", x2: "3", y1: "12", y2: "12" }]
    ]
  ];

  const LogOut = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" }],
      ["polyline", { points: "16 17 21 12 16 7" }],
      ["line", { x1: "21", x2: "9", y1: "12", y2: "12" }]
    ]
  ];

  const Logs = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M13 12h8" }],
      ["path", { d: "M13 18h8" }],
      ["path", { d: "M13 6h8" }],
      ["path", { d: "M3 12h1" }],
      ["path", { d: "M3 18h1" }],
      ["path", { d: "M3 6h1" }],
      ["path", { d: "M8 12h1" }],
      ["path", { d: "M8 18h1" }],
      ["path", { d: "M8 6h1" }]
    ]
  ];

  const Mail = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "20", height: "16", x: "2", y: "4", rx: "2" }],
      ["path", { d: "m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" }]
    ]
  ];

  const Map = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z"
        }
      ],
      ["path", { d: "M15 5.764v15" }],
      ["path", { d: "M9 3.236v15" }]
    ]
  ];

  const MapPin = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"
        }
      ],
      ["circle", { cx: "12", cy: "10", r: "3" }]
    ]
  ];

  const Megaphone = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "m3 11 18-5v12L3 14v-3z" }],
      ["path", { d: "M11.6 16.8a3 3 0 1 1-5.8-1.6" }]
    ]
  ];

  const Menu = [
    "svg",
    defaultAttributes,
    [
      ["line", { x1: "4", x2: "20", y1: "12", y2: "12" }],
      ["line", { x1: "4", x2: "20", y1: "6", y2: "6" }],
      ["line", { x1: "4", x2: "20", y1: "18", y2: "18" }]
    ]
  ];

  const MessageCircle = [
    "svg",
    defaultAttributes,
    [["path", { d: "M7.9 20A9 9 0 1 0 4 16.1L2 22Z" }]]
  ];

  const MessageSquare = [
    "svg",
    defaultAttributes,
    [["path", { d: "M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" }]]
  ];

  const MessageSquareCode = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M10 7.5 8 10l2 2.5" }],
      ["path", { d: "m14 7.5 2 2.5-2 2.5" }],
      ["path", { d: "M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" }]
    ]
  ];

  const Minus = ["svg", defaultAttributes, [["path", { d: "M5 12h14" }]]];

  const Monitor = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "20", height: "14", x: "2", y: "3", rx: "2" }],
      ["line", { x1: "8", x2: "16", y1: "21", y2: "21" }],
      ["line", { x1: "12", x2: "12", y1: "17", y2: "21" }]
    ]
  ];

  const Option = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M3 3h6l6 18h6" }],
      ["path", { d: "M14 3h7" }]
    ]
  ];

  const Package = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"
        }
      ],
      ["path", { d: "M12 22V12" }],
      ["path", { d: "m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7" }],
      ["path", { d: "m7.5 4.27 9 5.15" }]
    ]
  ];

  const PackageCheck = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "m16 16 2 2 4-4" }],
      [
        "path",
        {
          d: "M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"
        }
      ],
      ["path", { d: "m7.5 4.27 9 5.15" }],
      ["polyline", { points: "3.29 7 12 12 20.71 7" }],
      ["line", { x1: "12", x2: "12", y1: "22", y2: "12" }]
    ]
  ];

  const Pencil = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"
        }
      ],
      ["path", { d: "m15 5 4 4" }]
    ]
  ];

  const Percent = [
    "svg",
    defaultAttributes,
    [
      ["line", { x1: "19", x2: "5", y1: "5", y2: "19" }],
      ["circle", { cx: "6.5", cy: "6.5", r: "2.5" }],
      ["circle", { cx: "17.5", cy: "17.5", r: "2.5" }]
    ]
  ];

  const Phone = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"
        }
      ]
    ]
  ];

  const PhoneCall = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"
        }
      ],
      ["path", { d: "M14.05 2a9 9 0 0 1 8 7.94" }],
      ["path", { d: "M14.05 6A5 5 0 0 1 18 10" }]
    ]
  ];

  const Pin = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M12 17v5" }],
      [
        "path",
        {
          d: "M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"
        }
      ]
    ]
  ];

  const Plus = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M5 12h14" }],
      ["path", { d: "M12 5v14" }]
    ]
  ];

  const Printer = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" }],
      ["path", { d: "M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6" }],
      ["rect", { x: "6", y: "14", width: "12", height: "8", rx: "1" }]
    ]
  ];

  const Quote = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M16 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"
        }
      ],
      [
        "path",
        {
          d: "M5 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"
        }
      ]
    ]
  ];

  const Radio = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M4.9 19.1C1 15.2 1 8.8 4.9 4.9" }],
      ["path", { d: "M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5" }],
      ["circle", { cx: "12", cy: "12", r: "2" }],
      ["path", { d: "M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5" }],
      ["path", { d: "M19.1 4.9C23 8.8 23 15.1 19.1 19" }]
    ]
  ];

  const RectangleEllipsis = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "20", height: "12", x: "2", y: "6", rx: "2" }],
      ["path", { d: "M12 12h.01" }],
      ["path", { d: "M17 12h.01" }],
      ["path", { d: "M7 12h.01" }]
    ]
  ];

  const Regex = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M17 3v10" }],
      ["path", { d: "m12.67 5.5 8.66 5" }],
      ["path", { d: "m12.67 10.5 8.66-5" }],
      ["path", { d: "M9 17a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2v-2z" }]
    ]
  ];

  const Rocket = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"
        }
      ],
      [
        "path",
        {
          d: "m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"
        }
      ],
      ["path", { d: "M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0" }],
      ["path", { d: "M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5" }]
    ]
  ];

  const RotateCcw = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" }],
      ["path", { d: "M3 3v5h5" }]
    ]
  ];

  const Rows2 = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "18", height: "18", x: "3", y: "3", rx: "2" }],
      ["path", { d: "M3 12h18" }]
    ]
  ];

  const Save = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"
        }
      ],
      ["path", { d: "M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7" }],
      ["path", { d: "M7 3v4a1 1 0 0 0 1 1h7" }]
    ]
  ];

  const Scale = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z" }],
      ["path", { d: "m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z" }],
      ["path", { d: "M7 21h10" }],
      ["path", { d: "M12 3v18" }],
      ["path", { d: "M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2" }]
    ]
  ];

  const Scan = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M3 7V5a2 2 0 0 1 2-2h2" }],
      ["path", { d: "M17 3h2a2 2 0 0 1 2 2v2" }],
      ["path", { d: "M21 17v2a2 2 0 0 1-2 2h-2" }],
      ["path", { d: "M7 21H5a2 2 0 0 1-2-2v-2" }]
    ]
  ];

  const Search = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "11", cy: "11", r: "8" }],
      ["path", { d: "m21 21-4.3-4.3" }]
    ]
  ];

  const Section = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M16 5a4 3 0 0 0-8 0c0 4 8 3 8 7a4 3 0 0 1-8 0" }],
      ["path", { d: "M8 19a4 3 0 0 0 8 0c0-4-8-3-8-7a4 3 0 0 1 8 0" }]
    ]
  ];

  const Send = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"
        }
      ],
      ["path", { d: "m21.854 2.147-10.94 10.939" }]
    ]
  ];

  const Settings = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"
        }
      ],
      ["circle", { cx: "12", cy: "12", r: "3" }]
    ]
  ];

  const Share = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8" }],
      ["polyline", { points: "16 6 12 2 8 6" }],
      ["line", { x1: "12", x2: "12", y1: "2", y2: "15" }]
    ]
  ];

  const ShieldCheck = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"
        }
      ],
      ["path", { d: "m9 12 2 2 4-4" }]
    ]
  ];

  const ShoppingBag = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z" }],
      ["path", { d: "M3 6h18" }],
      ["path", { d: "M16 10a4 4 0 0 1-8 0" }]
    ]
  ];

  const ShoppingCart = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "8", cy: "21", r: "1" }],
      ["circle", { cx: "19", cy: "21", r: "1" }],
      [
        "path",
        { d: "M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12" }
      ]
    ]
  ];

  const Signature = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "m21 17-2.156-1.868A.5.5 0 0 0 18 15.5v.5a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1c0-2.545-3.991-3.97-8.5-4a1 1 0 0 0 0 5c4.153 0 4.745-11.295 5.708-13.5a2.5 2.5 0 1 1 3.31 3.284"
        }
      ],
      ["path", { d: "M3 21h18" }]
    ]
  ];

  const Smartphone = [
    "svg",
    defaultAttributes,
    [
      ["rect", { width: "14", height: "20", x: "5", y: "2", rx: "2", ry: "2" }],
      ["path", { d: "M12 18h.01" }]
    ]
  ];

  const Sparkles = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"
        }
      ],
      ["path", { d: "M20 3v4" }],
      ["path", { d: "M22 5h-4" }],
      ["path", { d: "M4 17v2" }],
      ["path", { d: "M5 18H3" }]
    ]
  ];

  const SquarePen = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" }],
      [
        "path",
        {
          d: "M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"
        }
      ]
    ]
  ];

  const Star = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"
        }
      ]
    ]
  ];

  const Stethoscope = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M11 2v2" }],
      ["path", { d: "M5 2v2" }],
      ["path", { d: "M5 3H4a2 2 0 0 0-2 2v4a6 6 0 0 0 12 0V5a2 2 0 0 0-2-2h-1" }],
      ["path", { d: "M8 15a6 6 0 0 0 12 0v-3" }],
      ["circle", { cx: "20", cy: "10", r: "2" }]
    ]
  ];

  const StickyNote = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M16 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8Z" }],
      ["path", { d: "M15 3v4a2 2 0 0 0 2 2h4" }]
    ]
  ];

  const Table = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M12 3v18" }],
      ["rect", { width: "18", height: "18", x: "3", y: "3", rx: "2" }],
      ["path", { d: "M3 9h18" }],
      ["path", { d: "M3 15h18" }]
    ]
  ];

  const Target = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "12", cy: "12", r: "10" }],
      ["circle", { cx: "12", cy: "12", r: "6" }],
      ["circle", { cx: "12", cy: "12", r: "2" }]
    ]
  ];

  const Text = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M17 6.1H3" }],
      ["path", { d: "M21 12.1H3" }],
      ["path", { d: "M15.1 18H3" }]
    ]
  ];

  const ThumbsUp = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M7 10v12" }],
      [
        "path",
        {
          d: "M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"
        }
      ]
    ]
  ];

  const Timer = [
    "svg",
    defaultAttributes,
    [
      ["line", { x1: "10", x2: "14", y1: "2", y2: "2" }],
      ["line", { x1: "12", x2: "15", y1: "14", y2: "11" }],
      ["circle", { cx: "12", cy: "14", r: "8" }]
    ]
  ];

  const Trash2 = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M3 6h18" }],
      ["path", { d: "M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" }],
      ["path", { d: "M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" }],
      ["line", { x1: "10", x2: "10", y1: "11", y2: "17" }],
      ["line", { x1: "14", x2: "14", y1: "11", y2: "17" }]
    ]
  ];

  const TrendingDown = [
    "svg",
    defaultAttributes,
    [
      ["polyline", { points: "22 17 13.5 8.5 8.5 13.5 2 7" }],
      ["polyline", { points: "16 17 22 17 22 11" }]
    ]
  ];

  const TrendingUp = [
    "svg",
    defaultAttributes,
    [
      ["polyline", { points: "22 7 13.5 15.5 8.5 10.5 2 17" }],
      ["polyline", { points: "16 7 22 7 22 13" }]
    ]
  ];

  const TriangleAlert = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3" }],
      ["path", { d: "M12 9v4" }],
      ["path", { d: "M12 17h.01" }]
    ]
  ];

  const Truck = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2" }],
      ["path", { d: "M15 18H9" }],
      [
        "path",
        { d: "M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14" }
      ],
      ["circle", { cx: "17", cy: "18", r: "2" }],
      ["circle", { cx: "7", cy: "18", r: "2" }]
    ]
  ];

  const Type = [
    "svg",
    defaultAttributes,
    [
      ["polyline", { points: "4 7 4 4 20 4 20 7" }],
      ["line", { x1: "9", x2: "15", y1: "20", y2: "20" }],
      ["line", { x1: "12", x2: "12", y1: "4", y2: "20" }]
    ]
  ];

  const Upload = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" }],
      ["polyline", { points: "17 8 12 3 7 8" }],
      ["line", { x1: "12", x2: "12", y1: "3", y2: "15" }]
    ]
  ];

  const User = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" }],
      ["circle", { cx: "12", cy: "7", r: "4" }]
    ]
  ];

  const UserCheck = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" }],
      ["circle", { cx: "9", cy: "7", r: "4" }],
      ["polyline", { points: "16 11 18 13 22 9" }]
    ]
  ];

  const UserCog = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "18", cy: "15", r: "3" }],
      ["circle", { cx: "9", cy: "7", r: "4" }],
      ["path", { d: "M10 15H6a4 4 0 0 0-4 4v2" }],
      ["path", { d: "m21.7 16.4-.9-.3" }],
      ["path", { d: "m15.2 13.9-.9-.3" }],
      ["path", { d: "m16.6 18.7.3-.9" }],
      ["path", { d: "m19.1 12.2.3-.9" }],
      ["path", { d: "m19.6 18.7-.4-1" }],
      ["path", { d: "m16.8 12.3-.4-1" }],
      ["path", { d: "m14.3 16.6 1-.4" }],
      ["path", { d: "m20.7 13.8 1-.4" }]
    ]
  ];

  const UserMinus = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" }],
      ["circle", { cx: "9", cy: "7", r: "4" }],
      ["line", { x1: "22", x2: "16", y1: "11", y2: "11" }]
    ]
  ];

  const UserPlus = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" }],
      ["circle", { cx: "9", cy: "7", r: "4" }],
      ["line", { x1: "19", x2: "19", y1: "8", y2: "14" }],
      ["line", { x1: "22", x2: "16", y1: "11", y2: "11" }]
    ]
  ];

  const UserRoundSearch = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "10", cy: "8", r: "5" }],
      ["path", { d: "M2 21a8 8 0 0 1 10.434-7.62" }],
      ["circle", { cx: "18", cy: "18", r: "3" }],
      ["path", { d: "m22 22-1.9-1.9" }]
    ]
  ];

  const UserX = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" }],
      ["circle", { cx: "9", cy: "7", r: "4" }],
      ["line", { x1: "17", x2: "22", y1: "8", y2: "13" }],
      ["line", { x1: "22", x2: "17", y1: "8", y2: "13" }]
    ]
  ];

  const Users = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" }],
      ["circle", { cx: "9", cy: "7", r: "4" }],
      ["path", { d: "M22 21v-2a4 4 0 0 0-3-3.87" }],
      ["path", { d: "M16 3.13a4 4 0 0 1 0 7.75" }]
    ]
  ];

  const UsersRound = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M18 21a8 8 0 0 0-16 0" }],
      ["circle", { cx: "10", cy: "8", r: "5" }],
      ["path", { d: "M22 20c0-3.37-2-6.5-4-8a5 5 0 0 0-.45-8.3" }]
    ]
  ];

  const Video = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5" }],
      ["rect", { x: "2", y: "6", width: "14", height: "12", rx: "2" }]
    ]
  ];

  const View = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M21 17v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2" }],
      ["path", { d: "M21 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2" }],
      ["circle", { cx: "12", cy: "12", r: "1" }],
      [
        "path",
        {
          d: "M18.944 12.33a1 1 0 0 0 0-.66 7.5 7.5 0 0 0-13.888 0 1 1 0 0 0 0 .66 7.5 7.5 0 0 0 13.888 0"
        }
      ]
    ]
  ];

  const Wallet = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"
        }
      ],
      ["path", { d: "M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4" }]
    ]
  ];

  const Watch = [
    "svg",
    defaultAttributes,
    [
      ["circle", { cx: "12", cy: "12", r: "6" }],
      ["polyline", { points: "12 10 12 12 13 13" }],
      ["path", { d: "m16.13 7.66-.81-4.05a2 2 0 0 0-2-1.61h-2.68a2 2 0 0 0-2 1.61l-.78 4.05" }],
      ["path", { d: "m7.88 16.36.8 4a2 2 0 0 0 2 1.61h2.72a2 2 0 0 0 2-1.61l.81-4.05" }]
    ]
  ];

  const X = [
    "svg",
    defaultAttributes,
    [
      ["path", { d: "M18 6 6 18" }],
      ["path", { d: "m6 6 12 12" }]
    ]
  ];

  const Youtube = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M2.5 17a24.12 24.12 0 0 1 0-10 2 2 0 0 1 1.4-1.4 49.56 49.56 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24.12 24.12 0 0 1 0 10 2 2 0 0 1-1.4 1.4 49.55 49.55 0 0 1-16.2 0A2 2 0 0 1 2.5 17"
        }
      ],
      ["path", { d: "m10 15 5-3-5-3z" }]
    ]
  ];

  const Zap = [
    "svg",
    defaultAttributes,
    [
      [
        "path",
        {
          d: "M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"
        }
      ]
    ]
  ];

  var iconAndAliases = /*#__PURE__*/Object.freeze({
    __proto__: null,
    AlertCircle: CircleAlert,
    AlertTriangle: TriangleAlert,
    Archive: Archive,
    ArrowLeft: ArrowLeft,
    ArrowRight: ArrowRight,
    ArrowUp: ArrowUp,
    Award: Award,
    Baby: Baby,
    BarChart3: ChartColumn,
    Bell: Bell,
    BookOpen: BookOpen,
    Cake: Cake,
    Calendar: Calendar,
    CalendarCheck: CalendarCheck,
    CalendarClock: CalendarClock,
    CalendarDays: CalendarDays,
    Cat: Cat,
    Check: Check,
    CheckCircle2: CircleCheck,
    ChevronDown: ChevronDown,
    ChevronRight: ChevronRight,
    Clapperboard: Clapperboard,
    ClipboardCheck: ClipboardCheck,
    ClipboardList: ClipboardList,
    Clock: Clock,
    Code: Code,
    Columns: Columns2,
    Contact: Contact,
    Copy: Copy,
    CreditCard: CreditCard,
    Database: Database,
    Delete: Delete,
    Download: Download,
    Edit: SquarePen,
    Eye: Eye,
    EyeOff: EyeOff,
    Facebook: Facebook,
    File: File,
    FileText: FileText,
    Files: Files,
    Fingerprint: Fingerprint,
    Flame: Flame,
    Focus: Focus,
    Folder: Folder,
    Footprints: Footprints,
    FormInput: RectangleEllipsis,
    GraduationCap: GraduationCap,
    Grid: Grid3x3,
    Group: Group,
    HardDriveDownload: HardDriveDownload,
    Heading: Heading,
    Headphones: Headphones,
    HeartHandshake: HeartHandshake,
    HelpCircle: CircleHelp,
    History: History,
    Home: House,
    Image: Image,
    Import: Import,
    Inbox: Inbox,
    Info: Info,
    Key: Key,
    Layers: Layers,
    LayoutDashboard: LayoutDashboard,
    LayoutGrid: LayoutGrid,
    Link: Link,
    List: List,
    ListChecks: ListChecks,
    Lock: Lock,
    LogIn: LogIn,
    LogOut: LogOut,
    Logs: Logs,
    Mail: Mail,
    Map: Map,
    MapPin: MapPin,
    Megaphone: Megaphone,
    Menu: Menu,
    MessageCircle: MessageCircle,
    MessageSquare: MessageSquare,
    MessageSquareCode: MessageSquareCode,
    Minus: Minus,
    Monitor: Monitor,
    Option: Option,
    Package: Package,
    PackageCheck: PackageCheck,
    Pencil: Pencil,
    Percent: Percent,
    Phone: Phone,
    PhoneCall: PhoneCall,
    PieChart: ChartPie,
    Pin: Pin,
    PlayCircle: CirclePlay,
    Plus: Plus,
    PlusCircle: CirclePlus,
    Printer: Printer,
    Quote: Quote,
    Radio: Radio,
    Regex: Regex,
    Rocket: Rocket,
    RotateCcw: RotateCcw,
    Rows: Rows2,
    Save: Save,
    Scale: Scale,
    Scan: Scan,
    Search: Search,
    Section: Section,
    Send: Send,
    Settings: Settings,
    Share: Share,
    ShieldCheck: ShieldCheck,
    ShoppingBag: ShoppingBag,
    ShoppingCart: ShoppingCart,
    Signature: Signature,
    Smartphone: Smartphone,
    Sparkles: Sparkles,
    Star: Star,
    Stethoscope: Stethoscope,
    StickyNote: StickyNote,
    Table: Table,
    Target: Target,
    Text: Text,
    ThumbsUp: ThumbsUp,
    Timer: Timer,
    Trash2: Trash2,
    TrendingDown: TrendingDown,
    TrendingUp: TrendingUp,
    Truck: Truck,
    Type: Type,
    Upload: Upload,
    User: User,
    UserCheck: UserCheck,
    UserCircle: CircleUser,
    UserCog: UserCog,
    UserMinus: UserMinus,
    UserPlus: UserPlus,
    UserRoundSearch: UserRoundSearch,
    UserX: UserX,
    Users: Users,
    UsersRound: UsersRound,
    Verified: BadgeCheck,
    Video: Video,
    View: View,
    Wallet: Wallet,
    Watch: Watch,
    X: X,
    XCircle: CircleX,
    Youtube: Youtube,
    Zap: Zap
  });

  const createIcons = ({ icons = iconAndAliases, nameAttr = "data-lucide", attrs = {} } = {}) => {
    if (!Object.values(icons).length) {
      throw new Error(
        "Please provide an icons object.\nIf you want to use all the icons you can import it like:\n `import { createIcons, icons } from 'lucide';\nlucide.createIcons({icons});`"
      );
    }
    if (typeof document === "undefined") {
      throw new Error("`createIcons()` only works in a browser environment.");
    }
    const elementsToReplace = document.querySelectorAll(`[${nameAttr}]`);
    Array.from(elementsToReplace).forEach(
      (element) => replaceElement(element, { nameAttr, icons, attrs })
    );
    if (nameAttr === "data-lucide") {
      const deprecatedElements = document.querySelectorAll("[icon-name]");
      if (deprecatedElements.length > 0) {
        console.warn(
          "[Lucide] Some icons were found with the now deprecated icon-name attribute. These will still be replaced for backwards compatibility, but will no longer be supported in v1.0 and you should switch to data-lucide"
        );
        Array.from(deprecatedElements).forEach(
          (element) => replaceElement(element, { nameAttr: "icon-name", icons, attrs })
        );
      }
    }
  };

  // ── নিরাপত্তা-জাল: এই ছোট ফাইলে নেই এমন আইকন পেলে পূর্ণ বান্ডল নামিয়ে আবার আঁকা ──
  var fullSrc = (function () {
    try { var s = document.currentScript && document.currentScript.src; return s ? s.replace(/lucide\.js(\?.*)?$/, 'lucide-full.js') : ''; } catch (e) { return ''; }
  })();
  var fullAsked = false;
  const createIconsSafe = (opts) => {
    createIcons(opts);
    try {
      if (fullAsked || !fullSrc || !document.querySelector('i[data-lucide]')) return;
      fullAsked = true;
      var el = document.createElement('script');
      el.src = fullSrc;
      el.onload = function () { try { if (window.lucide && window.lucide.createIcons) window.lucide.createIcons(opts); } catch (e) {} };
      document.head.appendChild(el);
    } catch (e) {}
  };

  exports.createElement = createElement$1;
  exports.createIcons = createIconsSafe;
  exports.icons = iconAndAliases;

}));
