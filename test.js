const fs = require('fs');
const html = fs.readFileSync('public_html/scratch_output.html', 'utf8');

const matches = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)];
const jsCode = matches[matches.length - 1][1];

const domEvents = {};
const mockElements = {
    '#skillsList': { appendChild: (el) => { console.log('Appended to skillsList:', el.innerHTML.substring(0, 100)); } },
    '#competenciesList': { appendChild: (el) => { console.log('Appended to compList:', el.innerHTML.substring(0, 100)); } },
    '#projectsList': { appendChild: (el) => { console.log('Appended to projList:', el.innerHTML.substring(0, 100)); } },
    '#criteriaList': { appendChild: (el) => { console.log('Appended to criteriaList:', el.innerHTML.substring(0, 100)); } },
    '#terrellMap': { appendChild: (el) => { console.log('Appended to terrellMap:', el.innerHTML.substring(0, 100)); }, addEventListener: (name, cb) => { console.log('Bound terrellMap event'); } },
    '#addSkillBtn': { addEventListener: (name, cb) => { console.log('Bound addSkillBtn event'); } },
    '#addCompetencyBtn': { addEventListener: (name, cb) => { console.log('Bound addCompetencyBtn event'); } },
    '#addProjectBtn': { addEventListener: (name, cb) => { console.log('Bound addProjectBtn event'); } },
    '#addCriterionBtn': { addEventListener: (name, cb) => { console.log('Bound addCriterionBtn event'); } },
    '#step-indicators-container': { set innerHTML(val) { console.log('Indicators HTML updated'); } },
    'avatarUpload': { addEventListener: (name, cb) => {} },
    'avatarPreview': { dataset: {} },
    'weakness_other': { classList: { remove: () => {}, add: () => {} }, removeAttribute: () => {}, setAttribute: () => {} }
};

const documentMock = {
    querySelector: (sel) => {
        if (sel === 'input[name="is_published"]') return { checked: false, addEventListener: () => {} };
        if (sel === 'button[type="submit"]') return { classList: { remove: () => {}, add: () => {} }, set innerHTML(val) {} };
        if (sel.startsWith('[name="reflections')) return { set value(val) {} };
        if (mockElements[sel]) return mockElements[sel];
        return null;
    },
    querySelectorAll: (sel) => {
        if (sel === 'input[name="weakness"]') return [];
        if (sel === '.project-status-select') return [];
        if (sel === '.step') return [];
        if (sel === '.step-indicator') return [];
        if (sel === '.btn-next' || sel === '.btn-prev') return [];
        return [];
    },
    getElementById: (id) => {
        if (id === 'step-basic' || id === 'step-whoami' || id === 'step-goal' || id === 'step-competencies' || id === 'step-progress' || id === 'step-reflection') {
            return { classList: { add: () => {}, remove: () => {} }, querySelector: () => null };
        }
        if (id === 'avatarUpload' || id === 'avatarPreview' || id === 'weakness_other') return mockElements[id];
        if (mockElements[id]) return mockElements[id];
        return null;
    },
    createElement: (tag) => {
        return {
            className: '',
            style: {},
            innerHTML: '',
            setAttribute: () => {},
            querySelector: () => ({ addEventListener: () => {} })
        };
    },
    addEventListener: (event, cb) => {
        domEvents[event] = cb;
    }
};

const context = {
    document: documentMock,
    window: { addEventListener: () => {} },
    console: console,
    Array: Array
};

try {
    const fn = new Function('document', 'window', 'console', 'Array', jsCode);
    fn(documentMock, context.window, console, Array);
    console.log('Script loaded successfully.');
    if (domEvents['DOMContentLoaded']) {
        domEvents['DOMContentLoaded']();
        console.log('DOMContentLoaded executed successfully.');
    }
} catch (e) {
    console.error('CRASHED:', e);
}
