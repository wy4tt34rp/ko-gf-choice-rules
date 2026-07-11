/**
 * KO – GF Choice Rules Frontend Engine
 * Version: 2.8.0
 * Description: Evaluates Simple + Advanced Gravity Forms choice-lock rules.
 * No jQuery required. Compatible with GF conditional logic.
 */

/* eslint-env es6 */
/* global Set, Map, Promise */

(function () {
  "use strict";

  function parseJsonSafe(str, fallback) {
    if (!str) return fallback;
    try {
      return JSON.parse(str);
    } catch (e) {
      return fallback;
    }
  }

  function normalize(str) {
    if (str == null) return "";
    return String(str)
      .replace(/\u00A0/g, " ")
      .replace(/\s+/g, " ")
      .trim()
      .toLowerCase();
  }

  function baseVal(str) {
    if (str == null) return "";
    var parts = String(str).split("|", 2);
    return parts[0].trim();
  }

  function detectFormId(form) {
    // Try gform_XX
    if (form.id && form.id.indexOf("gform_") === 0) {
      var idPart = form.id.replace("gform_", "");
      var idNum = parseInt(idPart, 10);
      if (!isNaN(idNum)) return idNum;
    }
    // Fallback: hidden input gf_form_id
    var hidden = form.querySelector('input[name="gform_submit"]');
    if (hidden && hidden.value) {
      var idNum2 = parseInt(hidden.value, 10);
      if (!isNaN(idNum2)) return idNum2;
    }
    return null;
  }

  function getFieldInputs(form, fieldId) {
    if (!fieldId) return [];
    return Array.prototype.slice.call(
      form.querySelectorAll('[name="input_' + fieldId + '"]')
    );
  }

  function getFieldValue(form, fieldId) {
    var inputs = getFieldInputs(form, fieldId);
    if (!inputs.length) return "";

    var first = inputs[0];
    var tag = (first.tagName || "").toLowerCase();
    var type = (first.type || "").toLowerCase();

    if (type === "radio") {
      var checked = form.querySelector(
        '[name="input_' + fieldId + '"]:checked'
      );
      return checked ? checked.value : "";
    }

    if (type === "checkbox") {
      var checkedBoxes = inputs.filter(function (i) {
        return i.checked;
      });
      return checkedBoxes
        .map(function (i) {
          return i.value;
        })
        .join(",");
    }

    if (tag === "select") {
      if (first.multiple) {
        var selected = Array.prototype.slice.call(
          first.querySelectorAll("option:checked")
        );
        return selected
          .map(function (o) {
            return o.value;
          })
          .join(",");
      }
      return first.value;
    }

    // text, number, etc.
    return first.value;
  }

  function getChoiceWrapper(input) {
    if (!input) return null;
    return (
      input.closest(".gchoice") ||
      input.closest("li") ||
      input.closest(".gfield_radio li") ||
      input.parentElement
    );
  }

  function resetChoiceState(input) {
    var wrap = getChoiceWrapper(input);
    if (wrap) {
      wrap.style.display = "";
      wrap.classList.remove("ko-gf-disabled-choice");
      wrap.style.opacity = "";
      wrap.style.pointerEvents = "";
    }
    input.disabled = false;
  }

  function applyActionToChoice(input, action) {
    var wrap = getChoiceWrapper(input);
    if (!wrap) return;

    if (action === "hide") {
      if (input.checked) {
        input.checked = false;
        // Trigger change so GF conditional logic reacts
        var ev = new Event("change", { bubbles: true });
        input.dispatchEvent(ev);
      }
      wrap.style.display = "none";
    } else if (action === "disable") {
      if (input.checked) {
        input.checked = false;
        var ev2 = new Event("change", { bubbles: true });
        input.dispatchEvent(ev2);
      }
      input.disabled = true;
      wrap.classList.add("ko-gf-disabled-choice");
      wrap.style.opacity = "0.5";
      wrap.style.pointerEvents = "none";
    }
  }

  function evaluateNumericCondition(cond, valueRaw, form) {
    if (valueRaw == null || valueRaw === "") return false;

    var cleaned = String(valueRaw).replace(/,/g, "");
    var num = parseFloat(cleaned);
    if (isNaN(num)) return false;

    var unitMode = cond.unit_mode || "";
    if (unitMode === "miles_km" && cond.unit_field) {
      var unitVal = getFieldValue(form, cond.unit_field) || "";
      var unitNorm = unitVal.toString().toLowerCase();
      if (
        unitNorm.indexOf("kilometer") !== -1 ||
        unitNorm.indexOf("km") !== -1
      ) {
        num = num * 0.621371; // km -> miles
      }
    }

    var thresholdRaw = cond.value != null ? String(cond.value) : "";
    var threshold = parseFloat(thresholdRaw.replace(/,/g, ""));
    if (isNaN(threshold)) {
      // No usable threshold; treat as pass
      return true;
    }

    var op = cond.operator || "=";

    switch (op) {
      case "<":
        return num < threshold;
      case "<=":
        return num <= threshold;
      case ">":
        return num > threshold;
      case ">=":
        return num >= threshold;
      case "=":
        return num === threshold;
      case "!=":
        return num !== threshold;
      default:
        return num === threshold;
    }
  }

  function evaluateStringCondition(cond, valueRaw) {
    var valNorm = normalize(valueRaw);
    var targetNorm = normalize(cond.value);

    var op = cond.operator || "=";
    switch (op) {
      case "=":
        return valNorm === targetNorm;
      case "!=":
        return valNorm !== targetNorm;
      case "contains":
        return valNorm.indexOf(targetNorm) !== -1;
      default:
        return valNorm === targetNorm;
    }
  }

  function evaluateAdvancedCondition(cond, form) {
    var fieldId = cond.field_id;
    if (!fieldId) return true; // nothing to check

    var rawValue = getFieldValue(form, fieldId);

    if ((cond.type || "numeric") === "numeric") {
      return evaluateNumericCondition(cond, rawValue, form);
    }

    return evaluateStringCondition(cond, rawValue);
  }

  function evaluateSimpleRules(form, formId, rules) {
    if (!Array.isArray(rules) || !rules.length) return;

    rules.forEach(function (rule) {
      if (parseInt(rule.form_id, 10) !== formId) return;

      var triggerFieldId = parseInt(rule.trigger_field, 10);
      var targetFieldId = parseInt(rule.target_field, 10);
      if (!triggerFieldId || !targetFieldId) return;

      var triggerValCurrent = getFieldValue(form, triggerFieldId);
      var triggerMatches =
        normalize(triggerValCurrent) === normalize(rule.trigger_value);

      var logicMode = rule.logic_mode || "when_trigger_not_match";
      var shouldAct =
        logicMode === "when_trigger_match"
          ? triggerMatches
          : !triggerMatches;

      if (!shouldAct) return;

      var targetInputs = getFieldInputs(form, targetFieldId);
      if (!targetInputs.length) return;

      var targetBase = baseVal(rule.target_value);
      targetInputs.forEach(function (input) {
        if (baseVal(input.value) === targetBase) {
          applyActionToChoice(input, rule.action || "hide");
        }
      });
    });
  }

  function evaluateAdvancedRules(form, formId, advRules) {
    if (!Array.isArray(advRules) || !advRules.length) return;

    advRules.forEach(function (rule) {
      if (parseInt(rule.form_id, 10) !== formId) return;
      if (!Array.isArray(rule.conditions) || !rule.conditions.length) return;

      var targetFieldId = parseInt(rule.target_field, 10);
      if (!targetFieldId) return;

      var action = rule.action || "hide";
      var targetBase = baseVal(rule.target_value);

      var targetInputs = getFieldInputs(form, targetFieldId);
      if (!targetInputs.length) return;

      targetInputs.forEach(function (input) {
        if (baseVal(input.value) !== targetBase) return;

        var allPass = rule.conditions.every(function (cond) {
          return evaluateAdvancedCondition(cond, form);
        });

        if (!allPass) {
          // Conditions NOT met: apply action (hide/disable)
          applyActionToChoice(input, action);
        }
        // If allPass === true, do nothing; base visible/enabled state wins
      });
    });
  }

  function evaluateAllRulesForForm(form, formId, simpleRules, advRules) {
    // Collect all target fields we touch so we can reset them first
    var targetIds = new Set();

    if (Array.isArray(simpleRules)) {
      simpleRules.forEach(function (r) {
        if (parseInt(r.form_id, 10) === formId && r.target_field) {
          targetIds.add(parseInt(r.target_field, 10));
        }
      });
    }
    if (Array.isArray(advRules)) {
      advRules.forEach(function (r) {
        if (parseInt(r.form_id, 10) === formId && r.target_field) {
          targetIds.add(parseInt(r.target_field, 10));
        }
      });
    }

    // Reset state for all those target fields
    targetIds.forEach(function (fieldId) {
      var inputs = getFieldInputs(form, fieldId);
      inputs.forEach(resetChoiceState);
    });

    // Apply simple, then advanced
    evaluateSimpleRules(form, formId, simpleRules);
    evaluateAdvancedRules(form, formId, advRules);
  }

  function initForForms(simpleRules, advRules) {
    var forms = document.querySelectorAll(".gform_wrapper form");
    if (!forms.length) return;

    Array.prototype.forEach.call(forms, function (form) {
      var formId = detectFormId(form);
      if (!formId) return;

      var handler = function () {
        evaluateAllRulesForForm(form, formId, simpleRules, advRules);
      };

      // Initial evaluation
      handler();

      // Re-evaluate on any input/change in the form
      form.addEventListener("change", handler);
      form.addEventListener("input", handler);
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    // Find the script tag with our data attributes
    var script = Array.prototype.slice
      .call(document.scripts)
      .find(function (s) {
        return s.dataset && (s.dataset.koRules || s.dataset.koAdvRules);
      });

    if (!script) return;

    var simpleRules = parseJsonSafe(script.dataset.koRules, []);
    var advRules = parseJsonSafe(script.dataset.koAdvRules, []);

    initForForms(simpleRules, advRules);
  });
})();